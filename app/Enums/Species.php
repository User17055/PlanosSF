<?php

namespace App\Enums;

enum Species: string
{
    case Dog = 'dog';
    case Cat = 'cat';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Dog => 'Cão',
            self::Cat => 'Gato',
            self::Other => 'Outro',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
