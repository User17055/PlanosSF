<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Fatura normalizada a partir do provedor. O status original fica preservado em providerStatus.
 */
final class BillData
{
    /**
     * @param  array<int, array{id: string, status: string, provider_status: string, amount_cents: int, paid_at: ?CarbonImmutable, method: ?string}>  $charges
     */
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $subscriptionExternalId,
        public readonly string $status,
        public readonly string $providerStatus,
        public readonly int $amountCents,
        public readonly string $dueDate,
        public readonly ?string $periodStart,
        public readonly ?string $periodEnd,
        public readonly ?int $cycle,
        public readonly ?string $paymentUrl,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly array $charges,
    ) {}

    public function paidCents(): int
    {
        return array_sum(array_map(fn ($c) => $c['status'] === 'paid' ? $c['amount_cents'] : 0, $this->charges));
    }

    public function firstPaidAt(): ?CarbonImmutable
    {
        $paid = array_filter(array_map(fn ($c) => $c['status'] === 'paid' ? $c['paid_at'] : null, $this->charges));

        return $paid ? min($paid) : null;
    }
}
