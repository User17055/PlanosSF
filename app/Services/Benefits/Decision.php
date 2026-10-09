<?php

namespace App\Services\Benefits;

use App\Support\BusinessTime;
use Carbon\CarbonInterface;

/**
 * Resultado "autorizado" ou "negado" com código estável, motivo compreensível, data de liberação e saldo.
 */
final class Decision
{
    /**
     * @param  array<int, array{rule: string, ok: bool, detail?: string}>  $checks
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
        public readonly ?CarbonInterface $availableFrom = null,
        public readonly ?int $limit = null,
        public readonly ?int $available = null,
        public readonly array $checks = [],
        public readonly ?CarbonInterface $periodStart = null,
        public readonly ?CarbonInterface $periodEnd = null,
    ) {}

    public static function allow(?int $limit, ?int $available, array $checks, ?CarbonInterface $ps = null, ?CarbonInterface $pe = null): self
    {
        return new self(true, 'AUTHORIZED', 'Autorizado.', null, $limit, $available, $checks, $ps, $pe);
    }

    public static function deny(string $code, string $message, array $checks, ?CarbonInterface $availableFrom = null, ?int $limit = null, ?int $available = null): self
    {
        return new self(false, $code, $message, $availableFrom, $limit, $available, $checks);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'code' => $this->code,
            'message' => $this->message,
            'available_from' => $this->availableFrom?->toIso8601String(),
            'available_from_label' => $this->availableFrom ? BusinessTime::formatDateTime($this->availableFrom) : null,
            'limit' => $this->limit,
            'available' => $this->available,
            'period_start' => $this->periodStart?->toIso8601String(),
            'period_end' => $this->periodEnd?->toIso8601String(),
            'checks' => $this->checks,
        ];
    }
}
