<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras de calendário no fuso do negócio (America/Sao_Paulo); instantes de auditoria ficam em UTC.
 */
final class BusinessTime
{
    public static function tz(): string
    {
        return config('planos.business_timezone', 'America/Sao_Paulo');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function local(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::tz());
    }

    /**
     * Com apenas uma data civil de vencimento, a dívida vence no início do dia seguinte (seção 27.3).
     */
    public static function isPastDueDate(string $dueDate, ?CarbonInterface $at = null): bool
    {
        $at = $at ? self::local($at) : self::now();
        $deadline = CarbonImmutable::parse($dueDate, self::tz())->addDay()->startOfDay();

        return $at->greaterThanOrEqualTo($deadline);
    }

    /**
     * Soma meses preservando o dia de aniversário original: se o dia não existir no mês,
     * usa o último dia do mês sem deslocar os aniversários seguintes (seções 11 e "Definição dos períodos").
     */
    public static function addMonthsAnchored(CarbonImmutable $anchor, int $months): CarbonImmutable
    {
        $local = self::local($anchor);
        $target = $local->setDay(1)->addMonthsNoOverflow($months);
        $day = min($local->day, $target->daysInMonth);

        return $target->setDay($day)->setTime($local->hour, $local->minute, $local->second, $local->microsecond);
    }

    public static function formatDate(?CarbonInterface $instant): string
    {
        return $instant ? self::local($instant)->format('d/m/Y') : '—';
    }

    public static function formatDateTime(?CarbonInterface $instant): string
    {
        return $instant ? self::local($instant)->format('d/m/Y H:i') : '—';
    }
}
