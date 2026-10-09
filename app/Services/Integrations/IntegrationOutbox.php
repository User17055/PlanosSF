<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationStatus;
use App\Models\IntegrationJob;
use App\Services\Audit\AuditLogger;
use App\Support\Mask;
use Illuminate\Database\Eloquent\Model;

/**
 * Padrão outbox (seção 12.4): a intenção de integração é gravada na mesma transação da mudança local
 * e executada pela fila depois do commit. Cada destino tem fila e situação próprias.
 */
class IntegrationOutbox
{
    public function __construct(protected AuditLogger $audit) {}

    public function modeFor(string $destination): string
    {
        return match ($destination) {
            'vindi' => IntegrationJob::MODE_AUTOMATIC,
            'simplesvet' => config('planos.integrations.simplesvet_mode', 'manual'),
            'tudoprapet' => config('planos.integrations.tudoprapet_mode', 'manual'),
            default => IntegrationJob::MODE_MANUAL,
        };
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $destination, string $operation, Model $subject, array $payload, string $keySuffix): ?IntegrationJob
    {
        $key = implode(':', [$destination, $operation, class_basename($subject), $subject->getKey(), $keySuffix]);
        $existing = IntegrationJob::where('operation_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        $mode = $this->modeFor($destination);

        return IntegrationJob::create([
            'destination' => $destination,
            'operation' => $operation,
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'operation_key' => $key,
            'mode' => $mode,
            'status' => $mode === IntegrationJob::MODE_MANUAL ? IntegrationStatus::NeedsIntervention : IntegrationStatus::Pending,
            'payload' => Mask::payload($payload),
            'next_attempt_at' => now(),
            'franchise_id' => $subject->originating_franchise_id ?? $subject->created_franchise_id ?? null,
            'correlation_id' => $this->audit->correlationId(),
        ]);
    }
}
