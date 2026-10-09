<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Synced = 'synced';
    case Error = 'error';
    case NeedsIntervention = 'needs_intervention';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Processing => 'Em processamento',
            self::Synced => 'Sincronizada',
            self::Error => 'Erro',
            self::NeedsIntervention => 'Intervenção necessária',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
