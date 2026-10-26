<?php

namespace Tests\Feature;

use App\Models\Authorization;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitRule;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\WebhookInbox;
use App\Services\Benefits\BenefitService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Concorrência real: cada operação roda num processo PHP próprio, com conexão própria ao MySQL
 * (tests/Concurrency/worker.php). Por isso os dados são gravados de fato, sem a transação do
 * RefreshDatabase, e o banco é recriado antes e depois.
 *
 * Nos testes com bloqueio, este processo segura a linha do cliente até todos os filhos estarem
 * esperando por ela; ao soltar, eles disputam a mesma linha, como duas recepções no mesmo segundo.
 */
class ConcurrencyTest extends TestCase
{
    use BuildsScenario;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concorrência real só no MySQL (job da CI).');
        }
        Artisan::call('migrate:fresh');
        $this->setUpScenario();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Artisan::call('migrate:fresh');
        }
        parent::tearDown();
    }

    protected function rule(string $code): BenefitRule
    {
        return BenefitRule::where('plan_version_id', $this->version->id)->where('service_code', $code)->firstOrFail();
    }

    public function test_tres_recepcoes_disputando_a_ultima_unidade_geram_uma_so_reserva(): void
    {
        $sub = $this->activeSubscription();
        $rule = $this->rule('VACINA');
        $tasks = [];
        foreach ([1, 2, 3] as $i) {
            $tasks[] = $this->reserveTask($sub, $rule, $this->userWith('recepcao', $this->unitA)->id, "ultima-{$i}");
        }

        $results = $this->race($tasks, $sub->customer_id);

        $this->assertSame(1, Authorization::where('benefit_rule_id', $rule->id)->count(), json_encode($results));
        $this->assertSame(['BENEFIT_LIMIT_REACHED', 'BENEFIT_LIMIT_REACHED'], $this->deniedCodes($results), json_encode($results));
    }

    public function test_mesma_chave_de_reserva_em_paralelo_devolve_a_mesma_reserva_a_todos(): void
    {
        $sub = $this->activeSubscription();
        $rule = $this->rule('VACINA');
        $user = $this->userWith('recepcao', $this->unitA)->id;

        $results = $this->race(array_fill(0, 3, $this->reserveTask($sub, $rule, $user, 'clique-duplo')), $sub->customer_id);

        $this->assertSame(1, Authorization::count(), json_encode($results));
        $id = Authorization::value('id');
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], json_encode($r));
            $this->assertSame($id, $r['authorization_id'], 'Repetição da mesma requisição recebeu outra resposta: '.json_encode($r));
        }
    }

    public function test_dois_estornos_simultaneos_registram_um_so_e_o_outro_recebe_a_mensagem(): void
    {
        $sub = $this->activeSubscription();
        $user = $this->userWith('gestor', $this->unitA);
        $svc = app(BenefitService::class);
        $r = $svc->reserve($sub, $this->rule('VACINA'), 1, $user, $this->unitA, 'para-estornar');
        $svc->consume($r['authorization'], $user, $this->unitA);
        $task = ['op' => 'reverse', 'authorization_id' => $r['authorization']->id, 'user_id' => $user->id];

        $results = $this->race([$task, $task], $sub->customer_id);

        $this->assertSame(1, BenefitLedgerEntry::where('type', BenefitLedgerEntry::TYPE_REVERSAL)->count(), json_encode($results));
        $ok = array_values(array_filter($results, fn ($x) => $x['ok']));
        $failed = array_values(array_filter($results, fn ($x) => ! $x['ok']));
        $this->assertCount(1, $ok, json_encode($results));
        $this->assertSame(ValidationException::class, $failed[0]['error'], 'O segundo estorno deveria receber a mensagem, não um erro: '.json_encode($failed[0]));
    }

    public function test_mesmo_webhook_recebido_quatro_vezes_ao_mesmo_tempo_fica_registrado_uma_vez(): void
    {
        $payload = ['event' => [
            'id' => 'evt-paralelo', 'type' => 'bill_paid', 'created_at' => now()->toIso8601String(),
            'data' => ['bill' => ['id' => 'fake-bill-1']],
        ]];

        $results = $this->race(array_fill(0, 4, ['op' => 'webhook', 'payload' => $payload]));

        $this->assertSame(1, WebhookInbox::count(), json_encode($results));
        $id = WebhookInbox::value('id');
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], json_encode($r));
            $this->assertSame($id, $r['inbox_id']);
        }
    }

    /** @return array<string, mixed> */
    protected function reserveTask(Subscription $sub, BenefitRule $rule, int $userId, string $key): array
    {
        return ['op' => 'reserve', 'subscription_id' => $sub->id, 'rule_id' => $rule->id, 'user_id' => $userId,
            'franchise_id' => $this->unitA->id, 'key' => $key];
    }

    /** @param list<array<string, mixed>> $results @return list<string> */
    protected function deniedCodes(array $results): array
    {
        foreach ($results as $r) {
            $this->assertTrue($r['ok'], json_encode($r));
        }

        return array_values(array_map(fn ($r) => $r['code'], array_filter($results, fn ($r) => $r['authorization_id'] === null)));
    }

    /**
     * Dispara as tarefas em processos separados e devolve o resultado de cada uma, na ordem.
     * Com $holdCustomerId, segura a linha do cliente até todos estarem esperando por ela.
     *
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array<string, mixed>>
     */
    protected function race(array $tasks, ?int $holdCustomerId = null): array
    {
        $barrier = sys_get_temp_dir().'/largada-'.uniqid();
        $env = array_merge(getenv(), ['PLANOS_HOMOLOGATION' => 'true', 'PLANOS_BILLING_DRIVER' => 'fake', 'PLANOS_OTP_REQUIRED' => 'false']);
        $procs = [];
        foreach ($tasks as $task) {
            $proc = proc_open([PHP_BINARY, base_path('tests/Concurrency/worker.php'), json_encode($task + ['barrier' => $barrier])],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $procs[] = [$proc, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            $line = fgets($pipes[1]);
            if ($line !== "ready\n") {
                $this->fail('Processo filho não subiu: '.$line.stream_get_contents($pipes[2]));
            }
        }

        $blocked = null;
        if ($holdCustomerId) {
            DB::beginTransaction();
            Customer::whereKey($holdCustomerId)->lockForUpdate()->first();
        }
        touch($barrier);
        if ($holdCustomerId) {
            $blocked = $this->waitForLockWaiters(count($tasks));
            DB::commit();
        }

        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            proc_close($proc);
            $results[] = json_decode(trim($out), true) ?? ['ok' => false, 'error' => 'sem resposta', 'message' => $out.$err];
        }
        @unlink($barrier);

        if ($holdCustomerId) {
            $this->assertSame(count($tasks), $blocked, 'Nem todos os processos chegaram a esperar o bloqueio: '.json_encode($results));
        }

        return $results;
    }

    /** Espera (até 15 s) os filhos ficarem parados num SELECT ... FOR UPDATE; devolve quantos ficaram. */
    protected function waitForLockWaiters(int $count): int
    {
        $until = microtime(true) + 15;
        do {
            $waiting = (int) DB::selectOne("select count(*) as n from information_schema.processlist where id <> connection_id() and info like '%for update%'")->n;
            if ($waiting >= $count) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $until);

        return $waiting;
    }
}
