<?php

namespace App\Enums;

enum CoverageType: string
{
    case Included = 'included';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::Included => 'Cobertura incluída',
            self::Discount => 'Desconto',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
