<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Canceled = 'canceled';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando pagamento',
            self::Paid => 'Paga',
            self::PartiallyPaid => 'Parcialmente paga',
            self::Canceled => 'Cancelada',
            self::Refunded => 'Estornada',
            self::Disputed => 'Contestada',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
