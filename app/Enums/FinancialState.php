<?php

namespace App\Enums;

enum FinancialState: string
{
    case Regular = 'regular';
    case Delinquent = 'delinquent';
    case PendingVerification = 'pending_verification';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Delinquent => 'Inadimplente — serviços e benefícios bloqueados',
            self::PendingVerification => 'Verificação pendente',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
