<?php

/*
 * Processo filho dos testes de concorrência (tests/Feature/ConcurrencyTest.php).
 * Sobe a aplicação com conexão própria ao banco, avisa que está pronto, espera a largada (arquivo
 * de barreira) e executa uma operação. Responde uma linha JSON.
 */

use App\Models\Authorization;
use App\Models\BenefitRule;
use App\Models\Franchise;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Benefits\BenefitService;
use App\Services\Billing\WebhookReceiver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$task = json_decode($argv[1], true);
// Só o recebimento entra na disputa; o processamento do webhook fica fora.
Queue::fake();
DB::connection()->getPdo();

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
while (! file_exists($task['barrier'])) {
    usleep(200);
}

try {
    $result = match ($task['op']) {
        'reserve' => (function () use ($task) {
            $r = app(BenefitService::class)->reserve(
                Subscription::findOrFail($task['subscription_id']), BenefitRule::findOrFail($task['rule_id']), 1,
                User::findOrFail($task['user_id']), Franchise::findOrFail($task['franchise_id']), $task['key'],
            );

            return ['authorization_id' => $r['authorization']?->id, 'code' => $r['decision']->code];
        })(),
        'reverse' => [
            'entry_id' => app(BenefitService::class)->reverse(
                Authorization::findOrFail($task['authorization_id']), User::findOrFail($task['user_id']), 'estorno em paralelo',
            )->id,
        ],
        'webhook' => ['inbox_id' => app(WebhookReceiver::class)->receive('fake', $task['payload'])->id],
    };
    echo json_encode(['ok' => true] + $result), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 300)]), "\n";
}
