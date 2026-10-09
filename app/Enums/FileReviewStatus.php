<?php

namespace App\Enums;

enum FileReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando conferência',
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
            self::Superseded => 'Substituído',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
