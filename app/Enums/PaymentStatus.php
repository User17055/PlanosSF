<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PendingValidation = 'pending_validation';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::PendingValidation => 'Aguardando validação do financeiro',
            self::Confirmed => 'Confirmado',
            self::Rejected => 'Rejeitado',
            self::Refunded => 'Estornado',
            self::Disputed => 'Contestado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
