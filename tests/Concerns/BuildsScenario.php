<?php

namespace Tests\Concerns;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\ExternalMapping;
use App\Models\Franchise;
use App\Models\FranchiseUser;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Proposal;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Access\FranchiseContext;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\ReconciliationService;
use App\Services\Billing\WebhookReceiver;
use App\Services\Catalog\PlanCatalogService;
use App\Services\Contracts\ContractService;
use App\Services\Proposals\ProposalService;
use Database\Seeders\AccessSeeder;

/**
 * Base fictícia de homologação: tutores, pets elegíveis e não elegíveis, planos com carências e
 * limites mensais e anuais (seção 17 — Procedimento de homologação).
 */
trait BuildsScenario
{
    protected Franchise $central;

    protected Franchise $unitA;

    protected Franchise $unitB;

    protected PlanVersion $version;

    protected function setUpScenario(): void
    {
        config(['planos.otp.required' => false, 'planos.billing.driver' => 'fake', 'planos.is_homologation' => true]);
        $this->seed(AccessSeeder::class);
        $this->central = Franchise::where('is_central', true)->first();
        $this->unitA = Franchise::create(['code' => 'A', 'name' => 'Unidade A', 'status' => 'active']);
        $this->unitB = Franchise::create(['code' => 'B', 'name' => 'Unidade B', 'status' => 'active']);
        $this->version = $this->publishedPlan();
    }

    protected function publishedPlan(?array $rules = null): PlanVersion
    {
        $template = ContractTemplate::create(['name' => 'Modelo '.uniqid(), 'version' => 1, 'body' => 'Contrato {{ contrato.numero }} de {{ tutor.nome }} para {{ pet.nome }}.', 'status' => 'draft']);
        $plan = Plan::create(['code' => 'P'.random_int(1000, 99999), 'name' => 'Plano Teste', 'status' => 'active']);
        $version = $plan->versions()->create([
            'version' => 1, 'display_name' => 'Plano Teste', 'status' => 'draft', 'price_cents' => 10000,
            'enrollment_fee_cents' => 0, 'billing_interval_months' => 1, 'contract_duration_months' => 12,
            'renewal_policy' => 'Exemplo', 'eligible_species' => ['dog', 'cat'], 'min_age_months' => 2, 'max_age_months' => 180,
            'contract_template_id' => $template->id,
        ]);
        $version->benefitRules()->createMany($rules ?? [
            ['service_code' => 'CONSULTA', 'description' => 'Consulta', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 2,
                'unit' => 'consulta', 'period_type' => PeriodType::ContractYear, 'waiting_days' => 30],
            ['service_code' => 'AVALIACAO', 'description' => 'Avaliação', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 1,
                'unit' => 'avaliação', 'period_type' => PeriodType::MonthlyCycle, 'waiting_days' => 10],
            ['service_code' => 'VACINA', 'description' => 'Vacina', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 1,
                'unit' => 'dose', 'period_type' => PeriodType::ContractYear, 'waiting_days' => 0, 'allowed_franchise_ids' => [$this->unitA->id]],
        ]);

        return app(PlanCatalogService::class)->publish($version, new User);
    }

    protected function userWith(string $roleKey, Franchise $franchise): User
    {
        $user = User::create(['name' => ucfirst($roleKey).' '.$franchise->code, 'email' => uniqid($roleKey).'@teste.local', 'password' => 'senha-teste-123', 'is_active' => true]);
        FranchiseUser::create(['user_id' => $user->id, 'franchise_id' => $franchise->id, 'role_id' => Role::where('key', $roleKey)->value('id')]);

        return $user;
    }

    /** Executa código no contexto de um usuário e unidade (como o middleware faria). */
    protected function actingIn(User $user, Franchise $franchise): static
    {
        $this->actingAs($user);
        app(FranchiseContext::class)->set($user, $franchise);
        session([FranchiseContext::SESSION_KEY => $franchise->id]);

        return $this;
    }

    protected function customer(array $overrides = []): Customer
    {
        static $n = 0;
        $n++;

        return Customer::create(array_merge([
            'name' => 'Tutor '.$n, 'document_normalized' => $this->cpf($n), 'email' => "tutor{$n}@teste.local",
            'phone' => '11999990000', 'zip_code' => '01000-000', 'street' => 'Rua A', 'number' => '1',
            'city' => 'São Paulo', 'state' => 'SP',
        ], $overrides));
    }

    protected function pet(Customer $customer, array $overrides = []): Pet
    {
        return $customer->pets()->create(array_merge(['name' => 'Rex', 'species' => 'dog', 'birth_date' => now()->subYears(2)->toDateString()], $overrides));
    }

    protected function proposal(Customer $customer, Pet $pet, Franchise $origin, array $data = []): Proposal
    {
        return app(ProposalService::class)->create(array_merge([
            'plan_version_id' => $this->version->id, 'channel' => 'link', 'fill_mode' => 'seller',
            'customer_id' => $customer->id, 'pet_id' => $pet->id,
        ], $data), null, $origin);
    }

    protected function acceptDigital(Proposal $proposal): void
    {
        app(ContractService::class)->acceptDigital($proposal, [
            'ip' => '127.0.0.1', 'user_agent' => 'phpunit', 'session_id' => 'sess', 'affirmative_action' => 'teste',
        ], null);
    }

    /** Cliente aceita, gera cobrança e paga no provedor; a confirmação chega por webhook. */
    protected function activeSubscription(?Customer $customer = null, ?Franchise $origin = null): Subscription
    {
        $customer ??= $this->customer();
        $pet = $this->pet($customer);
        $proposal = $this->proposal($customer, $pet, $origin ?? $this->unitA);
        $this->acceptDigital($proposal);
        $sub = $proposal->fresh()->subscription;
        $invoice = app(CheckoutService::class)->start($sub);
        $this->payBill($invoice->external_id);

        return $sub->fresh();
    }

    protected function payBill(string $billId, ?string $type = 'bill_paid'): void
    {
        $fake = app(BillingGateway::class);
        $bill = $fake->pay($billId);
        app(WebhookReceiver::class)->receive('fake', ['event' => [
            'id' => 'evt-'.uniqid(), 'type' => $type, 'created_at' => now()->toIso8601String(),
            'data' => ['bill' => ['id' => $bill->externalId]],
        ]]);
    }

    protected function notifyBill(string $billId, string $type = 'bill_created'): void
    {
        app(WebhookReceiver::class)->receive('fake', ['event' => [
            'id' => 'evt-'.uniqid(), 'type' => $type, 'created_at' => now()->toIso8601String(),
            'data' => ['bill' => ['id' => $billId]],
        ]]);
    }

    protected function nextBill(Subscription $sub): Invoice
    {
        $ext = ExternalMapping::externalId('fake', $sub);
        $bill = app(BillingGateway::class)->createNextBill($ext);
        $this->notifyBill($bill->externalId);

        return Invoice::where('external_id', $bill->externalId)->firstOrFail();
    }

    /** Avança o tempo e executa a conciliação que, em produção, roda periodicamente. */
    protected function passDays(int $days): void
    {
        $this->travel($days)->days();
        app(ReconciliationService::class)->reconcileAll(false);
    }

    protected function cpf(int $seed): string
    {
        $base = str_pad((string) (100000000 + $seed * 7919 % 899999999), 9, '0', STR_PAD_LEFT);
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $base[$i] * (($t + 1) - $i);
            }
            $base .= ((10 * $sum) % 11) % 10;
        }

        return $base;
    }
}
