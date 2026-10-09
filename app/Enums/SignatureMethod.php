<?php

namespace App\Enums;

enum SignatureMethod: string
{
    case Digital = 'digital';
    case Tablet = 'tablet';
    case Paper = 'paper';

    public function label(): string
    {
        return match ($this) {
            self::Digital => 'Aceite digital',
            self::Tablet => 'Assinatura no tablet',
            self::Paper => 'Papel digitalizado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
