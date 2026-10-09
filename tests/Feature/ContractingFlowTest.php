<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\ProposalStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Contract;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\InPersonPaymentService;
use App\Services\Catalog\PlanCatalogService;
use App\Services\Contracts\ContractPdfService;
use App\Services\Contracts\ContractService;
use App\Services\Proposals\ProposalService;
use App\Services\Settings;
use App\Services\Subscriptions\ActivationService;
use App\Services\Subscriptions\Impediment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Seção 17 — contratação, contrato e ativação.
 */
class ContractingFlowTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_aceitar_gerar_documento_e_pagar_uma_vez_resulta_em_uma_assinatura_mesmo_com_clique_repetido(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);

        $this->acceptDigital($proposal);
        $this->acceptDigital($proposal);

        $sub = $proposal->fresh()->subscription;
        $i1 = app(CheckoutService::class)->start($sub);
        $i2 = app(CheckoutService::class)->start($sub->fresh());

        $this->assertSame(1, Contract::count());
        $this->assertSame(1, Subscription::count());
        $this->assertSame($i1->id, $i2->id);
        $this->assertSame(1, \DB::table('fake_billing_records')->where('type', 'subscription')->count());

        $this->payBill($i1->external_id);
        $sub->refresh();
        $this->assertSame(SubscriptionStatus::Active, $sub->status);
        $this->assertNotNull($sub->effective_start_at);
        $this->assertSame(ProposalStatus::Completed, $proposal->fresh()->status);
        $this->assertSame(DocumentStatus::Valid, $sub->contract->document_status);
        $this->assertNotNull($sub->contract->currentFinalFile());
    }

    public function test_vendedor_preenche_mas_plano_nao_avanca_sem_aceite_do_cliente(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA, ['fill_mode' => 'seller']);

        $this->assertSame(ProposalStatus::AwaitingAcceptance, $proposal->status);
        $this->assertNull($proposal->contract);
        $this->assertSame(0, Subscription::count());
    }

    public function test_pagamento_confirmado_com_contrato_ausente_mantem_bloqueio(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);

        // Falha de armazenamento do PDF: aceite registrado, documento pendente.
        config(['queue.default' => 'database']);
        $this->acceptDigital($proposal);
        config(['queue.default' => 'sync']);

        $sub = $proposal->fresh()->subscription;
        $invoice = app(CheckoutService::class)->start($sub);
        $this->payBill($invoice->external_id);

        $sub->refresh();
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->status);
        $this->assertContains(Impediment::ContractMissing, app(ActivationService::class)->impediments($sub));
        $this->assertNotContains(Impediment::FirstPaymentPending, app(ActivationService::class)->impediments($sub));

        // Reprocessamento do PDF ativa sem novo pagamento.
        app(ContractPdfService::class)->generateFinal($sub->contract);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
    }

    public function test_contrato_valido_sem_pagamento_permanece_aguardando_e_retorno_do_checkout_nao_ativa(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA, ['channel' => 'link']);
        $token = app(ProposalService::class)->issueLink($proposal, null);
        $this->acceptDigital($proposal);
        app(CheckoutService::class)->start($proposal->fresh()->subscription);

        // O navegador consulta a situação, mas nunca transforma a assinatura em ativa.
        $this->getJson(route('public.status', $token))->assertOk()->assertJson(['done' => false]);
        $this->get(route('public.proposal', $token))->assertOk();

        $sub = $proposal->fresh()->subscription;
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->status);
        $this->assertContains(Impediment::FirstPaymentPending, app(ActivationService::class)->impediments($sub));
    }

    public function test_vigencia_comeca_quando_os_dois_requisitos_sao_atendidos(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);

        // Contrato validado no dia 1 e pagamento confirmado no dia 3: a vigência começa no dia 3.
        $this->passDays(1);
        $this->acceptDigital($proposal);
        $sub = $proposal->fresh()->subscription;
        $invoice = app(CheckoutService::class)->start($sub);

        $this->passDays(2);
        $payDay = now()->toDateString();
        $this->payBill($invoice->external_id);

        $sub->refresh();
        $this->assertSame($payDay, $sub->effective_start_at->toDateString());
        $activatedAt = $sub->activated_at;

        // Reprocessamentos não regravam datas históricas.
        $this->notifyBill($invoice->external_id, 'bill_paid');
        $this->assertEquals($activatedAt, $sub->fresh()->activated_at);
    }

    public function test_papel_rejeitado_nao_ativa_e_substituicao_preserva_historico(): void
    {
        $customer = $this->customer();
        $pet = $this->pet($customer);
        $proposal = $this->proposal($customer, $pet, $this->unitA, ['channel' => 'in_person', 'signature_method' => 'paper']);
        $staff = $this->userWith('gestor', $this->unitA);
        $this->actingIn($staff, $this->unitA);

        $contracts = app(ContractService::class);
        $contract = $contracts->preparePaper($proposal, $staff);
        $this->assertSame(DocumentStatus::AwaitingSignature, $contract->document_status);

        $file = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n% contrato assinado\n%%EOF");
        $scan1 = $contracts->uploadScan($contract, $file, $staff, now()->toDateString());
        $contracts->reviewScan($scan1, $staff, false, 'Ilegível');

        $sub = $proposal->fresh()->subscription;
        $this->assertSame(DocumentStatus::Rejected, $contract->fresh()->document_status);
        $this->assertContains(Impediment::DocumentRejected, app(ActivationService::class)->impediments($sub));

        $file2 = UploadedFile::fake()->createWithContent('scan2.pdf', "%PDF-1.4\n% nova digitalizacao\n%%EOF");
        $scan2 = $contracts->uploadScan($contract->fresh(), $file2, $staff, now()->toDateString(), 'Nova digitalização legível');
        $contracts->reviewScan($scan2, $staff, true, null);

        $this->assertSame(2, $contract->files()->where('kind', 'signed_scan')->count());
        $this->assertSame('rejected', $scan1->fresh()->review_status->value);
        $this->assertSame(DocumentStatus::Valid, $contract->fresh()->document_status);
        // Documento válido, mas sem pagamento presencial confirmado: continua aguardando.
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->fresh()->status);
    }

    public function test_pagamento_presencial_exige_validacao_financeira_e_nao_duplica_cobranca(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA, ['channel' => 'in_person', 'signature_method' => 'tablet']);
        $seller = $this->userWith('vendedor', $this->unitA);
        $this->actingIn($seller, $this->unitA);

        $png = $this->signaturePng();
        app(ContractService::class)->signTablet($proposal, $png, $seller, ['ip' => '1.1.1.1', 'user_agent' => 'tablet', 'session_id' => 'x']);
        $sub = $proposal->fresh()->subscription;

        $checkout = app(CheckoutService::class);
        $invoice = $checkout->start($sub);
        $this->assertSame($invoice->id, $checkout->start($sub->fresh())->id);
        $this->assertSame('manual', $invoice->provider);

        $service = app(InPersonPaymentService::class);
        // Decisão pendente: recebimento presencial desabilitado; o plano segue aguardando.
        try {
            $service->register($invoice, ['method' => 'pix', 'amount_cents' => 10000, 'paid_at' => now(), 'reference' => 'PIX1'], $seller);
            $this->fail('Recebimento presencial deveria estar bloqueado pela decisão pendente.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('decisão pendente', $e->getMessage());
        }
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->fresh()->status);
    }

    public function test_pagamento_presencial_habilitado_so_ativa_apos_validacao(): void
    {
        app(Settings::class)->set('in_person_payment.enabled', true, false);
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA, ['channel' => 'in_person', 'signature_method' => 'tablet']);
        $seller = $this->userWith('vendedor', $this->unitA);
        $finance = $this->userWith('financeiro', $this->unitA);
        $this->actingIn($seller, $this->unitA);
        app(ContractService::class)->signTablet($proposal, $this->signaturePng(), $seller, ['ip' => null, 'user_agent' => null, 'session_id' => null]);
        $sub = $proposal->fresh()->subscription;
        $invoice = app(CheckoutService::class)->start($sub);

        $service = app(InPersonPaymentService::class);
        $payment = $service->register($invoice, ['method' => 'pix', 'amount_cents' => 10000, 'paid_at' => now(), 'reference' => 'PIX-123'], $seller);
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->fresh()->status);

        $this->actingIn($finance, $this->unitA);
        $service->validate($payment, $finance, true, null);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
    }

    public function test_proposta_vencida_ou_plano_despublicado_exige_nova_proposta(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        app(PlanCatalogService::class)->unpublish($this->version, new User, 'teste');

        $this->expectException(ValidationException::class);
        $this->acceptDigital($proposal);
    }

    public function test_versao_publicada_e_imutavel(): void
    {
        $this->expectException(\LogicException::class);
        $this->version->update(['price_cents' => 1]);
    }

    public function test_pet_inelegivel_e_recusado(): void
    {
        $customer = $this->customer();
        $pet = $this->pet($customer, ['species' => 'other']);
        $this->expectException(ValidationException::class);
        $this->proposal($customer, $pet, $this->unitA);
    }

    protected function signaturePng(): string
    {
        $img = imagecreatetruecolor(400, 150);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $black = imagecolorallocate($img, 0, 0, 0);
        imagesetthickness($img, 3);
        imageline($img, 20, 100, 380, 40, $black);
        imageline($img, 20, 40, 380, 120, $black);
        ob_start();
        imagepng($img);
        $png = ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
