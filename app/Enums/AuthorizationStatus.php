<?php

namespace App\Enums;

enum AuthorizationStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reservada',
            self::Consumed => 'Executada',
            self::Released => 'Liberada',
            self::Expired => 'Expirada',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
