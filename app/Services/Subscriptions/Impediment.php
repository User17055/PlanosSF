<?php

namespace App\Services\Subscriptions;

/**
 * Lista de impedimentos separada do status principal (seção 9).
 */
enum Impediment: string
{
    case CustomerIncomplete = 'customer_incomplete';
    case PetIneligible = 'pet_ineligible';
    case ContractMissing = 'contract_missing';
    case DocumentAwaitingReview = 'document_awaiting_review';
    case DocumentRejected = 'document_rejected';
    case FirstPaymentPending = 'first_payment_pending';
    case Delinquent = 'delinquent';
    case FutureStart = 'future_start';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::CustomerIncomplete => 'Cadastro incompleto',
            self::PetIneligible => 'Pet não elegível ao plano',
            self::ContractMissing => 'Contrato final ausente',
            self::DocumentAwaitingReview => 'Documento aguardando conferência',
            self::DocumentRejected => 'Documento rejeitado',
            self::FirstPaymentPending => 'Primeiro pagamento pendente',
            self::Delinquent => 'Inadimplência do cliente (bloqueio global)',
            self::FutureStart => 'Vigência futura',
            self::Terminated => 'Assinatura encerrada',
        };
    }

    /** Impedimentos que bloqueiam a primeira ativação. */
    public function blocksActivation(): bool
    {
        return $this !== self::Delinquent;
    }
}
