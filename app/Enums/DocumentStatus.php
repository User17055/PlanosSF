<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case AwaitingSignature = 'awaiting_signature';
    case AwaitingReview = 'awaiting_review';
    case Valid = 'valid';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::AwaitingSignature => 'Aguardando aceite/assinatura',
            self::AwaitingReview => 'Aguardando conferência',
            self::Valid => 'Válido',
            self::Rejected => 'Rejeitado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
