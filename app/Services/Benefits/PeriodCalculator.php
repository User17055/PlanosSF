<?php

namespace App\Services\Benefits;

use App\Enums\PeriodType;
use App\Models\BenefitRule;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Períodos com início inclusivo e fim exclusivo, calculados no fuso do negócio.
 * Ciclos ancorados preservam o aniversário original (31/01 → 28/02 ou 29/02 → 31/03).
 */
class PeriodCalculator
{
    /**
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable} [início, fim) em UTC; fim nulo = toda a vigência
     */
    public function periodFor(BenefitRule $rule, CarbonInterface $effectiveStart, CarbonInterface $at): array
    {
        $anchor = BusinessTime::local($effectiveStart);
        $now = BusinessTime::local($at);

        return match ($rule->period_type) {
            PeriodType::CalendarMonth => [
                $now->startOfMonth()->utc(),
                $now->startOfMonth()->addMonthNoOverflow()->utc(),
            ],
            PeriodType::MonthlyCycle => $this->anchored($anchor, $now, 1),
            PeriodType::ContractYear => $this->anchored($anchor, $now, 12),
            // Janela móvel e vigência total usam um único período de controle; a janela é aplicada na contagem.
            PeriodType::RollingWindow, PeriodType::WholeTerm, null => [$anchor->utc(), null],
        };
    }

    /** Início da janela móvel para contagem de consumos. */
    public function rollingWindowStart(BenefitRule $rule, CarbonInterface $at): ?CarbonImmutable
    {
        return $rule->period_type === PeriodType::RollingWindow && $rule->rolling_window_days
            ? CarbonImmutable::instance($at)->subDays($rule->rolling_window_days)
            : null;
    }

    /** Liberação no mesmo horário da ativação: available_from = effective_start_at + waiting_days. */
    public function availableFrom(BenefitRule $rule, CarbonInterface $effectiveStart): CarbonImmutable
    {
        return CarbonImmutable::instance($effectiveStart)->addDays($rule->waiting_days);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function anchored(CarbonImmutable $anchor, CarbonImmutable $now, int $months): array
    {
        if ($now->lt($anchor)) {
            return [$anchor->utc(), BusinessTime::addMonthsAnchored($anchor, $months)->utc()];
        }
        // Estimativa inicial e ajuste para garantir start <= now < end.
        $n = intdiv(((int) $anchor->diffInMonths($now)), $months);
        $start = BusinessTime::addMonthsAnchored($anchor, $n * $months);
        while ($start->gt($now)) {
            $n--;
            $start = BusinessTime::addMonthsAnchored($anchor, $n * $months);
        }
        $end = BusinessTime::addMonthsAnchored($anchor, ($n + 1) * $months);
        while ($end->lte($now)) {
            $n++;
            $start = $end;
            $end = BusinessTime::addMonthsAnchored($anchor, ($n + 1) * $months);
        }

        return [$start->utc(), $end->utc()];
    }
}
