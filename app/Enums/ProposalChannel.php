<?php

namespace App\Enums;

enum ProposalChannel: string
{
    case Site = 'site';
    case Link = 'link';
    case InPerson = 'in_person';

    public function label(): string
    {
        return match ($this) {
            self::Site => 'Site institucional',
            self::Link => 'Link individual',
            self::InPerson => 'Presencial',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
