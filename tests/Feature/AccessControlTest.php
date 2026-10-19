<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePortalCustomer;
use App\Models\FranchiseUser;
use App\Models\IntegrationJob;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookInbox;
use App\Services\Access\FranchiseContext;
use App\Services\Proposals\ProposalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Seções 15, 17 e 27.10 — permissões e escopo por franquia aplicados no servidor.
 */
class AccessControlTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_usuario_sem_permissao_nao_confirma_pagamento_nem_altera_cobertura(): void
    {
        $seller = $this->userWith('vendedor', $this->unitA);
        $payment = $this->activeSubscription()->invoices()->first()->payments()->first();

        $this->actingAs($seller)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('financial.validate', $payment), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($seller)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('financial.reconcile', $payment->invoice->subscription))->assertForbidden();
        $this->actingAs($seller)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('versions.publish', $this->version))->assertForbidden();
    }

    public function test_franquia_b_nao_ve_contrato_ou_proposta_da_franquia_a(): void
    {
        $sub = $this->activeSubscription(null, $this->unitA);
        $file = $sub->contract->currentFinalFile();
        $other = $this->userWith('gestor', $this->unitB);

        $this->actingAs($other)->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->get(route('documents.download', $file))->assertForbidden();
        $this->actingAs($other)->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->get(route('proposals.show', $sub->proposal))->assertForbidden();
        // Mas consegue consultar elegibilidade do mesmo cliente (identificação mínima).
        $this->actingAs($other)->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->get(route('benefits.subscription', $sub))->assertOk();
    }

    public function test_franquia_b_nao_registra_envio_de_link_de_proposta_da_franquia_a(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);

        $this->actingAs($this->userWith('vendedor', $this->unitB))->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->post(route('proposals.link.sent', $proposal), ['channel' => 'whatsapp'])->assertForbidden();
        $this->actingAs($this->userWith('vendedor', $this->unitA))->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('proposals.link.sent', $proposal), ['channel' => 'whatsapp'])->assertRedirect();
    }

    public function test_franquia_b_nao_reprocessa_integracao_da_franquia_a(): void
    {
        $this->activeSubscription(null, $this->unitA);
        $job = IntegrationJob::where('operation', 'link_planista')->firstOrFail();
        $this->assertSame($this->unitA->id, $job->franchise_id);

        $this->actingAs($this->userWith('admin_tecnico', $this->unitB))->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->post(route('integrations.retry', $job))->assertForbidden();
        $this->actingAs($this->userWith('admin_tecnico', $this->central))->withSession([FranchiseContext::SESSION_KEY => $this->central->id])
            ->post(route('integrations.retry', $job))->assertRedirect();
    }

    public function test_webhook_so_e_reprocessado_pela_central(): void
    {
        $this->activeSubscription(null, $this->unitA);
        Queue::fake();
        $inbox = WebhookInbox::firstOrFail();

        $this->actingAs($this->userWith('admin_tecnico', $this->unitB))->withSession([FranchiseContext::SESSION_KEY => $this->unitB->id])
            ->post(route('integrations.webhook.retry', $inbox))->assertForbidden();
        $this->actingAs($this->userWith('admin_tecnico', $this->central))->withSession([FranchiseContext::SESSION_KEY => $this->central->id])
            ->post(route('integrations.webhook.retry', $inbox))->assertRedirect();
    }

    public function test_vincular_proposta_a_cliente_existente_e_so_da_central(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);

        $this->actingAs($this->userWith('gestor', $this->unitA))->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('proposals.resolve', $proposal), ['customer_id' => $customer->id, 'reason' => 'teste'])->assertForbidden();
    }

    public function test_cadastro_de_pet_nao_redireciona_para_fora_do_sistema(): void
    {
        $customer = $this->customer();
        $user = $this->userWith('recepcao', $this->unitA);
        $pet = ['name' => 'Rex', 'species' => 'dog'];

        $this->actingAs($user)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('pets.store', $customer), $pet + ['return_to' => 'https://golpe.example/entrar'])
            ->assertRedirect(route('customers.show', $customer));
        $back = route('proposals.create', ['customer_id' => $customer->id]);
        $this->actingAs($user)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->post(route('pets.store', $customer), $pet + ['return_to' => $back])
            ->assertRedirect($back);
    }

    public function test_gestor_da_unidade_nao_altera_usuario_que_tambem_atua_fora_dela(): void
    {
        $gestorA = $this->userWith('gestor', $this->unitA);
        $central = $this->userWith('admin_tecnico', $this->central);
        FranchiseUser::create(['user_id' => $central->id, 'franchise_id' => $this->unitA->id, 'role_id' => Role::where('key', 'recepcao')->value('id')]);
        $soA = $this->userWith('recepcao', $this->unitA);
        // Trocar o e-mail já basta para assumir a conta (recuperação de senha); sem senha, o teste não consulta a rede.
        $data = fn (User $u) => ['name' => $u->name, 'email' => 'outro-'.$u->email, 'is_active' => 1];

        $this->actingAs($gestorA)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->put(route('users.update', $central), $data($central))->assertForbidden();
        $this->actingAs($gestorA)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->put(route('users.update', $soA), $data($soA))->assertRedirect();
    }

    public function test_trocar_franchise_id_na_sessao_nao_concede_acesso(): void
    {
        $sub = $this->activeSubscription(null, $this->unitA);
        $userB = $this->userWith('gestor', $this->unitB);

        // Sessão adulterada para a unidade A, sem vínculo: o servidor ignora e usa o vínculo real.
        $this->actingAs($userB)->withSession([FranchiseContext::SESSION_KEY => $this->unitA->id])
            ->get(route('subscriptions.show', $sub))->assertForbidden();
    }

    public function test_usuario_desativado_perde_acesso(): void
    {
        $user = $this->userWith('gestor', $this->unitA);
        $user->update(['is_active' => false]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_link_publico_nao_exibe_dados_pelo_numero_da_proposta(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        $this->get('/p/'.$proposal->number)->assertNotFound();
        $this->get('/p/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_link_revogado_mostra_mensagem_sem_dados(): void
    {
        $customer = $this->customer();
        $proposal = $this->proposal($customer, $this->pet($customer), $this->unitA);
        $svc = app(ProposalService::class);
        $old = $svc->issueLink($proposal, null);
        $new = $svc->issueLink($proposal->fresh(), null);

        $this->get(route('public.proposal', $old))->assertNotFound()->assertDontSee($customer->name);
        $this->get(route('public.proposal', $new))->assertOk();
    }

    public function test_portal_nao_exibe_assinatura_de_outro_cliente(): void
    {
        $mine = $this->activeSubscription();
        $theirs = $this->activeSubscription();

        $this->withSession([
            EnsurePortalCustomer::SESSION_KEY => $mine->customer_id,
            EnsurePortalCustomer::SESSION_EXPIRES => now()->addHour()->timestamp,
        ])->get(route('portal.subscription', $theirs))->assertNotFound();
    }

    public function test_webhook_sem_segredo_correto_e_recusado(): void
    {
        config(['planos.billing.vindi.webhook_secret' => 'segredo-certo']);
        $this->postJson('/webhooks/vindi/errado', ['event' => ['type' => 'bill_paid']])->assertNotFound();
        $this->postJson('/webhooks/vindi/segredo-certo', ['event' => ['type' => 'test']])->assertOk();
    }

    public function test_paginas_internas_principais_renderizam(): void
    {
        $sub = $this->activeSubscription();
        $admin = $this->userWith('gestor', $this->central);
        $s = fn () => $this->actingAs($admin)->withSession([FranchiseContext::SESSION_KEY => $this->central->id]);

        foreach ([
            route('dashboard'), route('customers.index', ['q' => 'Tutor']), route('customers.show', $sub->customer),
            route('proposals.index'), route('proposals.create', ['customer_id' => $sub->customer_id]), route('proposals.show', $sub->proposal),
            route('subscriptions.index'), route('subscriptions.show', $sub), route('benefits.index', ['q' => 'Tutor']),
            route('benefits.subscription', $sub), route('financial.index'), route('plans.index'), route('versions.show', $this->version),
            route('templates.index'), route('integrations.index'), route('reports.index'), route('audit.index'),
            route('users.index'), route('documents.index'),
        ] as $url) {
            $s()->get($url)->assertOk();
        }
        $this->get(route('site.plans'))->assertOk();
        $this->get(route('site.plan', $this->version))->assertOk();
    }
}
