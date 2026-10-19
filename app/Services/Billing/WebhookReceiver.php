<?php

namespace App\Services\Billing;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookInbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Caixa de entrada durável: persiste antes de responder sucesso; processa em fila.
 */
class WebhookReceiver
{
    /** @param array<string, mixed> $payload */
    public function receive(string $provider, array $payload): WebhookInbox
    {
        $event = $payload['event'] ?? [];
        $type = (string) ($event['type'] ?? 'unknown');
        $hash = hash('sha256', json_encode($payload));

        try {
            $inbox = WebhookInbox::create([
                'provider' => $provider,
                'event_id' => $event['id'] ?? null,
                'event_type' => mb_substr($type, 0, 80),
                'payload_hash' => $hash,
                'payload' => $payload,
                'provider_created_at' => isset($event['created_at']) ? CarbonImmutable::parse($event['created_at']) : null,
                'received_at' => now(),
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Reenvio do mesmo evento (mesmo id ou mesmo conteúdo): já está registrado; não duplica processamento.
            return WebhookInbox::where('provider', $provider)
                ->where(fn ($q) => $q->where('payload_hash', $hash)
                    ->when(isset($event['id']), fn ($q) => $q->orWhere('event_id', $event['id'])))
                ->firstOrFail();
        }

        ProcessWebhookEvent::dispatch($inbox->id)->onQueue('payments');

        return $inbox;
    }
}
