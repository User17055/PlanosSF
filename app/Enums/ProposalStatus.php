<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Created = 'created';
    case AwaitingFill = 'awaiting_fill';
    case AwaitingAcceptance = 'awaiting_acceptance';
    case AwaitingDocument = 'awaiting_document';
    case AwaitingPayment = 'awaiting_payment';
    case Completed = 'completed';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Criado',
            self::AwaitingFill => 'Aguardando preenchimento',
            self::AwaitingAcceptance => 'Aguardando aceite',
            self::AwaitingDocument => 'Aguardando documento',
            self::AwaitingPayment => 'Aguardando pagamento',
            self::Completed => 'Concluído',
            self::Expired => 'Expirado',
            self::Revoked => 'Revogado',
            self::Canceled => 'Cancelado',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
