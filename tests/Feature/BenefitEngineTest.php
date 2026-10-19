<?php

namespace Tests\Feature;

use App\Enums\AuthorizationStatus;
use App\Enums\CoverageType;
use App\Enums\PeriodType;
use App\Models\Authorization;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitRule;
use App\Services\Benefits\BenefitService;
use App\Services\Benefits\EligibilityService;
use App\Services\Benefits\PeriodCalculator;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Seção 11 e critérios da seção 17 sobre carência, saldo, reservas e períodos.
 */
class BenefitEngineTest extends TestCase
{
    use BuildsScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    protected function rule(string $code): BenefitRule
    {
        return BenefitRule::where('plan_version_id', $this->version->id)->where('service_code', $code)->firstOrFail();
    }

    public function test_beneficio_em_carencia_e_negado_com_data_correta(): void
    {
        $sub = $this->activeSubscription();
        $decision = app(EligibilityService::class)->evaluate($sub, $this->rule('CONSULTA'), $sub->pet_id, $this->unitA->id);

        $this->assertFalse($decision->allowed);
        $this->assertSame('BENEFIT_WAITING_PERIOD', $decision->code);
        $this->assertTrue($decision->availableFrom->equalTo($sub->effective_start_at->copy()->addDays(30)));
    }

    public function test_apos_carencia_respeita_limite_e_unidade_permitida(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $svc = app(BenefitService::class);
        $user = $this->userWith('recepcao', $this->unitA);

        foreach ([1, 2] as $i) {
            $r = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, "k{$i}");
            $this->assertNotNull($r['authorization']);
            $svc->consume($r['authorization'], $user, $this->unitA);
        }
        $r3 = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, 'k3');
        $this->assertNull($r3['authorization']);
        $this->assertSame('BENEFIT_LIMIT_REACHED', $r3['decision']->code);

        $other = app(EligibilityService::class)->evaluate($sub, $this->rule('VACINA'), $sub->pet_id, $this->unitB->id);
        $this->assertSame('UNIT_NOT_ALLOWED', $other->code);
    }

    public function test_ultima_unidade_produz_somente_uma_autorizacao_e_cancelar_reserva_devolve_saldo(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(11);
        $svc = app(BenefitService::class);
        $a = $this->userWith('recepcao', $this->unitA);
        $b = $this->userWith('recepcao', $this->unitB);

        $first = $svc->reserve($sub, $this->rule('AVALIACAO'), 1, $a, $this->unitA, 'rec-a');
        $second = $svc->reserve($sub, $this->rule('AVALIACAO'), 1, $b, $this->unitB, 'rec-b');
        $this->assertNotNull($first['authorization']);
        $this->assertNull($second['authorization']);

        $svc->release($first['authorization'], $a, 'cliente desmarcou');
        $third = $svc->reserve($sub, $this->rule('AVALIACAO'), 1, $b, $this->unitB, 'rec-c');
        $this->assertNotNull($third['authorization']);
    }

    public function test_chave_de_idempotencia_repetida_nao_reserva_de_novo(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $svc = app(BenefitService::class);
        $user = $this->userWith('recepcao', $this->unitA);
        $r1 = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, 'mesma');
        $r2 = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, 'mesma');
        $this->assertSame($r1['authorization']->id, $r2['authorization']->id);
        $this->assertSame(1, Authorization::count());
    }

    public function test_reserva_expirada_nao_pode_ser_consumida(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $svc = app(BenefitService::class);
        $user = $this->userWith('recepcao', $this->unitA);
        $r = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, 'exp');
        $this->travel(config('planos.benefits.reservation_ttl_hours') + 1)->hours();

        $d = $svc->consume($r['authorization'], $user, $this->unitA);
        $this->assertSame('AUTHORIZATION_EXPIRED', $d->code);
        $this->assertSame(AuthorizationStatus::Expired, $r['authorization']->fresh()->status);
    }

    public function test_estorno_de_utilizacao_cria_lancamento_inverso(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $svc = app(BenefitService::class);
        $user = $this->userWith('gestor', $this->unitA);
        $r = $svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, 'rev');
        $svc->consume($r['authorization'], $user, $this->unitA);
        $svc->reverse($r['authorization'], $user, 'lançado por engano');

        $this->assertSame(0, (int) BenefitLedgerEntry::sum('quantity'));
        $this->assertSame(2, BenefitLedgerEntry::count());
        $this->expectException(ValidationException::class);
        $svc->reverse($r['authorization'], $user, 'de novo');
    }

    public function test_estorno_de_consumo_que_ja_saiu_da_janela_movel_nao_devolve_saldo_extra(): void
    {
        $this->version = $this->publishedPlan([
            ['service_code' => 'BANHO', 'description' => 'Banho', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 1,
                'unit' => 'banho', 'period_type' => PeriodType::RollingWindow, 'rolling_window_days' => 30, 'waiting_days' => 0],
        ]);
        $sub = $this->activeSubscription();
        $svc = app(BenefitService::class);
        $user = $this->userWith('gestor', $this->unitA);
        $r = $svc->reserve($sub, $this->rule('BANHO'), 1, $user, $this->unitA, 'janela1');
        $svc->consume($r['authorization'], $user, $this->unitA);

        $next = $this->nextBill($sub);
        $this->payBill($next->external_id);
        $this->passDays(31);
        $svc->reverse($r['authorization'], $user, 'lançado por engano');

        $d = app(EligibilityService::class)->evaluate($sub->fresh(), $this->rule('BANHO'), $sub->pet_id, $this->unitA->id);
        $this->assertTrue($d->allowed);
        $this->assertSame(1, $d->available);
    }

    public function test_estorno_dentro_da_janela_movel_devolve_o_saldo(): void
    {
        $this->version = $this->publishedPlan([
            ['service_code' => 'BANHO', 'description' => 'Banho', 'coverage_type' => CoverageType::Included, 'quantity_limit' => 1,
                'unit' => 'banho', 'period_type' => PeriodType::RollingWindow, 'rolling_window_days' => 30, 'waiting_days' => 0],
        ]);
        $sub = $this->activeSubscription();
        $svc = app(BenefitService::class);
        $user = $this->userWith('gestor', $this->unitA);
        $r = $svc->reserve($sub, $this->rule('BANHO'), 1, $user, $this->unitA, 'janela2');
        $svc->consume($r['authorization'], $user, $this->unitA);
        $eligibility = app(EligibilityService::class);
        $this->assertSame('BENEFIT_LIMIT_REACHED', $eligibility->evaluate($sub->fresh(), $this->rule('BANHO'), $sub->pet_id, $this->unitA->id)->code);

        $svc->reverse($r['authorization'], $user, 'lançado por engano');
        $this->assertSame(1, $eligibility->evaluate($sub->fresh(), $this->rule('BANHO'), $sub->pet_id, $this->unitA->id)->available);
    }

    public function test_renovacao_mensal_nao_renova_saldo_anual(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $svc = app(BenefitService::class);
        $user = $this->userWith('recepcao', $this->unitA);
        foreach ([1, 2] as $i) {
            $svc->consume($svc->reserve($sub, $this->rule('CONSULTA'), 1, $user, $this->unitA, "a{$i}")['authorization'], $user, $this->unitA);
        }
        $svc->consume($svc->reserve($sub, $this->rule('AVALIACAO'), 1, $user, $this->unitA, 'm1')['authorization'], $user, $this->unitA);

        // Paga a mensalidade seguinte e avança um mês.
        $next = $this->nextBill($sub);
        $this->payBill($next->external_id);
        $this->passDays(31);

        $annual = app(EligibilityService::class)->evaluate($sub->fresh(), $this->rule('CONSULTA'), $sub->pet_id, $this->unitA->id);
        $monthly = app(EligibilityService::class)->evaluate($sub->fresh(), $this->rule('AVALIACAO'), $sub->pet_id, $this->unitA->id);
        $this->assertSame('BENEFIT_LIMIT_REACHED', $annual->code);
        $this->assertTrue($monthly->allowed);
    }

    /** Fevereiro, aniversários 29/30/31 e ano bissexto; início inclusivo e fim exclusivo. */
    public function test_periodos_ancorados_no_calendario_real(): void
    {
        $calc = app(PeriodCalculator::class);
        $monthly = new BenefitRule(['period_type' => PeriodType::MonthlyCycle]);
        $yearly = new BenefitRule(['period_type' => PeriodType::ContractYear]);
        $tz = BusinessTime::tz();
        $at = fn ($s) => CarbonImmutable::parse($s, $tz);
        $fmt = fn ($p) => [$p[0]->setTimezone($tz)->format('Y-m-d H:i'), $p[1]?->setTimezone($tz)->format('Y-m-d H:i')];

        $anchor = $at('2027-01-31 10:00');
        $this->assertSame(['2027-01-31 10:00', '2027-02-28 10:00'], $fmt($calc->periodFor($monthly, $anchor, $at('2027-02-10'))));
        $this->assertSame(['2027-02-28 10:00', '2027-03-31 10:00'], $fmt($calc->periodFor($monthly, $anchor, $at('2027-03-01'))));
        $this->assertSame(['2027-03-31 10:00', '2027-04-30 10:00'], $fmt($calc->periodFor($monthly, $anchor, $at('2027-04-15'))));

        $leap = $at('2028-01-30 08:00');
        $this->assertSame(['2028-01-30 08:00', '2028-02-29 08:00'], $fmt($calc->periodFor($monthly, $leap, $at('2028-02-29 07:59'))));
        $this->assertSame(['2028-02-29 08:00', '2028-03-30 08:00'], $fmt($calc->periodFor($monthly, $leap, $at('2028-02-29 08:00'))));

        $y = $at('2028-02-29 09:00');
        $this->assertSame(['2028-02-29 09:00', '2029-02-28 09:00'], $fmt($calc->periodFor($yearly, $y, $at('2028-12-31'))));
        $this->assertSame(['2029-02-28 09:00', '2030-02-28 09:00'], $fmt($calc->periodFor($yearly, $y, $at('2029-03-01'))));

        $cal = new BenefitRule(['period_type' => PeriodType::CalendarMonth]);
        $this->assertSame(['2026-12-01 00:00', '2027-01-01 00:00'], $fmt($calc->periodFor($cal, $anchor, $at('2026-12-31 23:59'))));
    }

    public function test_dados_de_vencimento_consideram_fim_do_dia_em_sao_paulo(): void
    {
        $this->assertFalse(BusinessTime::isPastDueDate('2026-10-10', CarbonImmutable::parse('2026-10-10 23:59', 'America/Sao_Paulo')));
        $this->assertTrue(BusinessTime::isPastDueDate('2026-10-10', CarbonImmutable::parse('2026-10-11 00:00', 'America/Sao_Paulo')));
        // 02:30 UTC do dia 11 ainda é dia 10 em São Paulo.
        $this->assertFalse(BusinessTime::isPastDueDate('2026-10-10', CarbonImmutable::parse('2026-10-11 02:30', 'UTC')));
    }
}
