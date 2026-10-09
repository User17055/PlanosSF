<?php

namespace App\Enums;

enum FillMode: string
{
    case Seller = 'seller';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Seller => 'Vendedor preenche',
            self::Customer => 'Cliente preenche',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
