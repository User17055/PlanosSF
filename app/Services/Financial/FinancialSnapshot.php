<?php

namespace App\Services\Financial;

use App\Enums\FinancialState;
use Carbon\CarbonInterface;

final class FinancialSnapshot
{
    /**
     * @param  array<int, int>  $overdueInvoiceIds
     */
    public function __construct(
        public readonly FinancialState $state,
        public readonly int $overdueCents,
        public readonly array $overdueInvoiceIds,
        public readonly ?string $oldestDueDate,
        public readonly ?CarbonInterface $lastSyncedAt,
        public readonly ?string $detail = null,
    ) {}

    public function isDelinquent(): bool
    {
        return $this->state === FinancialState::Delinquent;
    }

    public function isRegular(): bool
    {
        return $this->state === FinancialState::Regular;
    }

    /** Mensagem padronizada ao tentar lançar um serviço (seção 27.7). */
    public function blockMessage(): ?string
    {
        return match ($this->state) {
            FinancialState::Delinquent => 'Cliente com mensalidade vencida. Não é possível autorizar ou executar serviços e benefícios até a regularização.',
            FinancialState::PendingVerification => 'Verificação financeira pendente. Não é possível confirmar serviços até a atualização da situação financeira.',
            default => null,
        };
    }
}
