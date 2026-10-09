<?php

namespace Tests\Feature;

use App\Enums\FinancialState;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitRule;
use App\Models\IntegrationJob;
use App\Models\Payment;
use App\Models\SubscriptionEvent;
use App\Models\WebhookInbox;
use App\Services\Benefits\BenefitService;
use App\Services\Benefits\EligibilityService;
use App\Services\Billing\BillData;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\InvoiceSyncService;
use App\Services\Billing\ReconciliationService;
use App\Services\Billing\WebhookReceiver;
use App\Services\Financial\FinancialStatusService;
use App\Services\Subscriptions\CancellationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Seções 10, 12.1, 17 e 27.10 — inadimplência, bloqueio global e eventos do provedor.
 */
class FinancialBlockTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_reenvio_do_mesmo_webhook_nao_duplica_pagamento_nem_ativacao(): void
    {
        $sub = $this->activeSubscription();
        $invoice = $sub->invoices()->first();

        $payload = ['event' => ['id' => 'dup-1', 'type' => 'bill_paid', 'created_at' => now()->toIso8601String(), 'data' => ['bill' => ['id' => $invoice->external_id]]]];
        app(WebhookReceiver::class)->receive('fake', $payload);
        app(WebhookReceiver::class)->receive('fake', $payload);

        $this->assertSame(1, Payment::count());
        $this->assertSame(1, SubscriptionEvent::where('subscription_id', $sub->id)->where('event', 'activated')->count());
        $this->assertSame(1, WebhookInbox::where('event_id', 'dup-1')->count());
        $this->assertSame(1, IntegrationJob::where('operation', 'link_planista')->count());
    }

    public function test_evento_antigo_nao_regride_fatura_paga(): void
    {
        $sub = $this->activeSubscription();
        $invoice = $sub->invoices()->first();
        $stale = new BillData($invoice->external_id, null, 'pending', 'pending', $invoice->amount_cents,
            $invoice->due_date->format('Y-m-d'), null, null, 1, null, now()->subDay()->toImmutable(), []);

        app(InvoiceSyncService::class)->upsert($sub, $stale, 'fake');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
    }

    public function test_divida_vencida_na_franquia_a_bloqueia_atendimento_na_franquia_b(): void
    {
        $sub = $this->activeSubscription(null, $this->unitA);
        $this->passDays(40);
        $next = $this->nextBill($sub);
        $this->travelTo($next->due_date->copy()->addDays(2)->setTime(9, 0));
        app(FinancialStatusService::class)->reviewDueDates();

        $rule = BenefitRule::where('plan_version_id', $sub->plan_version_id)->where('service_code', 'CONSULTA')->first();
        $decision = app(EligibilityService::class)->evaluate($sub->fresh(), $rule, $sub->pet_id, $this->unitB->id);

        $this->assertFalse($decision->allowed);
        $this->assertSame('CUSTOMER_DELINQUENT', $decision->code);
        $this->assertSame(SubscriptionStatus::Suspended, $sub->fresh()->status);
        $this->assertSame(FinancialState::Delinquent, $sub->customer->financialStatus->status);
    }

    public function test_bloqueio_aplica_no_atendimento_mesmo_sem_rotina_agendada(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(20);
        $next = $this->nextBill($sub);
        $dueEnd = CarbonImmutable::parse($next->due_date->format('Y-m-d'), 'America/Sao_Paulo')->addDay();
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);

        // Último minuto do dia de vencimento em São Paulo: ainda não é dívida vencida.
        $this->travelTo($dueEnd->subMinute());
        $this->passDays(0);
        $rule = BenefitRule::where('plan_version_id', $sub->plan_version_id)->where('service_code', 'CONSULTA')->first();
        $this->assertNotSame('CUSTOMER_DELINQUENT', app(EligibilityService::class)->evaluate($sub->fresh(), $rule, $sub->pet_id, $this->unitA->id)->code);

        // Um minuto depois, sem nenhuma rotina rodar: a recepção já recebe o bloqueio.
        $this->travelTo($dueEnd->addMinute());
        $decision = app(EligibilityService::class)->evaluate($sub->fresh(), $rule, $sub->pet_id, $this->unitA->id);
        $this->assertSame('CUSTOMER_DELINQUENT', $decision->code);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
    }

    public function test_dois_pets_um_inadimplente_bloqueia_todos_e_pagamento_parcial_mantem_bloqueio(): void
    {
        $customer = $this->customer();
        $sub1 = $this->activeSubscription($customer, $this->unitA);
        $sub2 = $this->activeSubscription($customer, $this->unitB);

        $this->passDays(40);
        $b1 = $this->nextBill($sub1);
        $b2 = $this->nextBill($sub2);
        $this->travelTo($b1->due_date->copy()->max($b2->due_date)->addDays(3)->setTime(9, 0));
        app(FinancialStatusService::class)->reviewDueDates();

        $this->assertSame(SubscriptionStatus::Suspended, $sub1->fresh()->status);
        $this->assertSame(SubscriptionStatus::Suspended, $sub2->fresh()->status);

        // Paga apenas uma: continua bloqueado.
        $this->payBill($b1->external_id);
        $this->assertTrue(app(FinancialStatusService::class)->evaluate($customer)->isDelinquent());
        $this->assertSame(SubscriptionStatus::Suspended, $sub1->fresh()->status);

        // Quita tudo: reavalia e reativa.
        $this->payBill($b2->external_id);
        $this->assertTrue(app(FinancialStatusService::class)->evaluate($customer)->isRegular());
        $this->assertSame(SubscriptionStatus::Active, $sub1->fresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $sub2->fresh()->status);
    }

    public function test_parcela_futura_e_recusa_antes_do_vencimento_nao_caracterizam_inadimplencia(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(25);
        $next = $this->nextBill($sub);
        $this->assertTrue($next->due_date->isFuture() || $next->due_date->isToday());
        $this->assertTrue(app(FinancialStatusService::class)->evaluate($sub->customer)->isRegular());
    }

    public function test_pagamento_de_contrato_cancelado_nao_reativa(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(40);
        $next = $this->nextBill($sub);
        app(CancellationService::class)->request($sub->fresh(), 'immediate', 'Teste', $this->userWith('gestor', $this->unitA));

        $this->payBill($next->external_id);

        $this->assertSame(SubscriptionStatus::Canceled, $sub->fresh()->status);
        $this->assertTrue(SubscriptionEvent::where('subscription_id', $sub->id)->where('event', 'late_payment_after_cancellation')->exists());
    }

    public function test_cancelamento_de_um_plano_sem_divida_nao_afeta_o_outro(): void
    {
        $customer = $this->customer();
        $sub1 = $this->activeSubscription($customer);
        $sub2 = $this->activeSubscription($customer);
        app(CancellationService::class)->request($sub1->fresh(), 'immediate', 'Teste', $this->userWith('gestor', $this->unitA));

        $this->assertSame(SubscriptionStatus::Active, $sub2->fresh()->status);
        $this->assertTrue(app(FinancialStatusService::class)->evaluate($customer)->isRegular());
    }

    public function test_webhook_perdido_e_recuperado_pela_conciliacao(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        $this->acceptDigital($proposal);
        $sub = $proposal->fresh()->subscription;
        $invoice = app(CheckoutService::class)->start($sub);

        // Pago no provedor, mas o webhook nunca chegou.
        app(BillingGateway::class)->pay($invoice->external_id);
        $this->assertSame(SubscriptionStatus::AwaitingRegularization, $sub->fresh()->status);

        app(ReconciliationService::class)->reconcileAll(pendingOnly: true);
        $this->assertSame(SubscriptionStatus::Active, $sub->fresh()->status);
    }

    public function test_timeout_na_criacao_nao_produz_segunda_assinatura(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        $this->acceptDigital($proposal);
        $sub = $proposal->fresh()->subscription;

        // Provedor criou a assinatura, mas a resposta se perdeu.
        $fake = app(BillingGateway::class);
        $fake->createSubscription($sub, $fake->ensureCustomer($customer));
        $sub->forceFill(['billing_status' => 'uncertain', 'billing_provider' => 'fake'])->save();

        app(CheckoutService::class)->start($sub->fresh());

        $this->assertSame(1, \DB::table('fake_billing_records')->where('type', 'subscription')->count());
        $this->assertSame('created', $sub->fresh()->billing_status);
    }

    public function test_estorno_exige_recalculo_e_nao_restaura_saldo_executado(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $rule = BenefitRule::where('plan_version_id', $sub->plan_version_id)->where('service_code', 'CONSULTA')->first();
        $user = $this->userWith('recepcao', $this->unitA);
        $res = app(BenefitService::class)->reserve($sub, $rule, 1, $user, $this->unitA, 'k-estorno');
        app(BenefitService::class)->consume($res['authorization'], $user, $this->unitA);

        $invoice = $sub->invoices()->first();
        app(BillingGateway::class)->refund($invoice->external_id);
        $this->notifyBill($invoice->external_id, 'charge_refunded');

        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertTrue(SubscriptionEvent::where('subscription_id', $sub->id)->where('event', 'payment_reversed')->exists());
        $this->assertSame(1, BenefitLedgerEntry::where('type', 'consume')->count());
    }
}
