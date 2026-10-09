<?php

namespace App\Enums;

enum PeriodType: string
{
    case CalendarMonth = 'calendar_month';
    case MonthlyCycle = 'monthly_cycle';
    case ContractYear = 'contract_year';
    case RollingWindow = 'rolling_window';
    case WholeTerm = 'whole_term';

    public function label(): string
    {
        return match ($this) {
            self::CalendarMonth => 'Mês-calendário',
            self::MonthlyCycle => 'Ciclo mensal da ativação',
            self::ContractYear => 'Ano contratual',
            self::RollingWindow => 'Janela móvel',
            self::WholeTerm => 'Toda a vigência',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
