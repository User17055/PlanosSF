<?php

namespace Tests\Feature;

use App\Models\WebhookInbox;
use App\Services\Billing\WebhookReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Caixa de entrada de webhooks: reenvio do mesmo evento não duplica nem falha.
 */
class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_mesmo_evento_reenviado_com_conteudo_diferente_devolve_o_registro_existente(): void
    {
        Queue::fake();
        $receiver = app(WebhookReceiver::class);
        $first = $receiver->receive('vindi', ['event' => ['id' => 'evt-1', 'type' => 'bill_paid', 'created_at' => '2026-10-19T10:00:00-03:00', 'data' => ['bill' => ['id' => 10]]]]);
        $again = $receiver->receive('vindi', ['event' => ['id' => 'evt-1', 'type' => 'bill_paid', 'created_at' => '2026-10-19T10:05:00-03:00', 'data' => ['bill' => ['id' => 10]]]]);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WebhookInbox::count());
    }

    public function test_mesmo_conteudo_sem_id_devolve_o_registro_existente(): void
    {
        Queue::fake();
        $receiver = app(WebhookReceiver::class);
        $payload = ['event' => ['type' => 'bill_paid', 'data' => ['bill' => ['id' => 11]]]];
        $first = $receiver->receive('vindi', $payload);
        $again = $receiver->receive('vindi', $payload);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WebhookInbox::count());
    }
}
