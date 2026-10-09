<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Draft = 'draft';
    case AwaitingRegularization = 'awaiting_regularization';
    case Active = 'active';
    case Suspended = 'suspended';
    case CancellationScheduled = 'cancellation_scheduled';
    case Canceled = 'canceled';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::AwaitingRegularization => 'Aguardando regularização',
            self::Active => 'Ativa',
            self::Suspended => 'Suspensa',
            self::CancellationScheduled => 'Cancelamento agendado',
            self::Canceled => 'Cancelada',
            self::Ended => 'Encerrada',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
