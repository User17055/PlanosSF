<?php

namespace Tests\Feature;

use App\Enums\AuthorizationStatus;
use App\Enums\CoverageType;
use App\Enums\DocumentStatus;
use App\Enums\FileReviewStatus;
use App\Enums\FillMode;
use App\Enums\FinancialState;
use App\Enums\IntegrationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\PeriodType;
use App\Enums\PlanVersionStatus;
use App\Enums\ProposalChannel;
use App\Enums\ProposalStatus;
use App\Enums\SignatureMethod;
use App\Enums\Species;
use App\Enums\SubscriptionStatus;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitRule;
use App\Services\Benefits\BenefitService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Restrições CHECK da migration add_integrity_checks. Só valem no MySQL (job da CI e VPS).
 */
class IntegrityChecksTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    /** @var list<array{string, string, class-string<\BackedEnum>}> */
    protected array $enumColumns = [
        ['proposals', 'status', ProposalStatus::class],
        ['proposals', 'channel', ProposalChannel::class],
        ['proposals', 'fill_mode', FillMode::class],
        ['proposals', 'signature_method', SignatureMethod::class],
        ['contracts', 'signature_method', SignatureMethod::class],
        ['contracts', 'document_status', DocumentStatus::class],
        ['contract_files', 'review_status', FileReviewStatus::class],
        ['plan_versions', 'status', PlanVersionStatus::class],
        ['benefit_rules', 'coverage_type', CoverageType::class],
        ['benefit_rules', 'period_type', PeriodType::class],
        ['pets', 'species', Species::class],
        ['subscriptions', 'status', SubscriptionStatus::class],
        ['invoices', 'status', InvoiceStatus::class],
        ['payments', 'status', PaymentStatus::class],
        ['customer_financial_status', 'status', FinancialState::class],
        ['authorizations', 'status', AuthorizationStatus::class],
        ['integration_jobs', 'status', IntegrationStatus::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Restrições CHECK só existem no MySQL.');
        }
        $this->setUpScenario();
    }

    public function test_cada_check_aceita_exatamente_os_valores_do_enum(): void
    {
        foreach ($this->enumColumns as [$table, $column, $enum]) {
            $clause = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', "chk_{$table}_{$column}")
                ->value('CHECK_CLAUSE');
            $this->assertNotNull($clause, "Falta o CHECK chk_{$table}_{$column}.");

            // O MySQL pode guardar a cláusula com aspas escapadas: (`status` in (_utf8mb4\'created\',...)).
            preg_match_all("/'([a-z_]+)'/", str_replace('\\', '', $clause), $m);
            $inDatabase = $m[1];
            $inEnum = array_map(fn ($case) => $case->value, $enum::cases());
            sort($inDatabase);
            sort($inEnum);
            $this->assertSame($inEnum, $inDatabase, "{$table}.{$column} diverge de {$enum}: crie uma migration nova.");
        }
    }

    public function test_estado_fora_do_enum_e_recusado_pelo_banco(): void
    {
        $this->expectException(QueryException::class);
        DB::table('plan_versions')->where('id', $this->version->id)->update(['status' => 'publicado']);
    }

    public function test_lancamento_de_beneficio_incoerente_e_recusado_e_o_estorno_legitimo_passa(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $user = $this->userWith('recepcao', $this->unitA);
        $rule = BenefitRule::where('plan_version_id', $this->version->id)->where('service_code', 'CONSULTA')->firstOrFail();
        $svc = app(BenefitService::class);
        $auth = $svc->reserve($sub, $rule, 1, $user, $this->unitA, 'k1')['authorization'];
        $svc->consume($auth, $user, $this->unitA);
        $consume = BenefitLedgerEntry::where('authorization_id', $auth->id)->firstOrFail();

        $refused = 0;
        $attempts = [
            fn () => DB::table('benefit_ledger')->where('id', $consume->id)->update(['quantity' => -1]),
            fn () => DB::table('benefit_ledger')->insert([
                'authorization_id' => $auth->id, 'benefit_period_id' => $consume->benefit_period_id,
                'type' => 'reversal', 'quantity' => -1, 'original_entry_id' => null, 'dedupe_key' => 'manual-1',
                'servicing_franchise_id' => $this->unitA->id, 'actor_id' => $user->id, 'occurred_at' => now(),
            ]),
            fn () => DB::table('benefit_ledger')->where('id', $consume->id)->update(['type' => 'ajuste']),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (QueryException) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused);

        $reversal = $svc->reverse($auth->fresh(), $user, 'teste de estorno');
        $this->assertSame(-1, $reversal->quantity);
        $this->assertSame($consume->id, $reversal->original_entry_id);
    }

    public function test_reserva_com_quantidade_zero_e_recusada(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $rule = BenefitRule::where('plan_version_id', $this->version->id)->where('service_code', 'CONSULTA')->firstOrFail();
        $auth = app(BenefitService::class)->reserve($sub, $rule, 1, $this->userWith('recepcao', $this->unitA), $this->unitA, 'k1')['authorization'];

        $this->expectException(QueryException::class);
        DB::table('authorizations')->where('id', $auth->id)->update(['quantity' => 0]);
    }
}
