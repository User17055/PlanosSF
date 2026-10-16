<?php

namespace Tests\Feature;

use App\Models\BenefitLedgerEntry;
use App\Models\BenefitRule;
use App\Services\Benefits\BenefitService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsScenario;
use Tests\TestCase;

/**
 * Permissões do usuário da aplicação (deploy/app-grants.sh). Só roda no passo da CI que conecta como
 * esse usuário (PLANOS_CHECK_APP_GRANTS=1), com o banco já migrado pelo dono.
 */
class AppDatabaseUserTest extends TestCase
{
    use BuildsScenario, DatabaseTransactions;

    /** Código do MySQL para comando negado por falta de permissão. */
    protected const DENIED = 1142;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('PLANOS_CHECK_APP_GRANTS')) {
            $this->markTestSkipped('Só no passo da CI que conecta como o usuário da aplicação.');
        }
        $this->setUpScenario();
    }

    public function test_consumo_e_estorno_funcionam_com_o_usuario_da_aplicacao(): void
    {
        $sub = $this->activeSubscription();
        $this->passDays(31);
        $user = $this->userWith('recepcao', $this->unitA);
        $rule = BenefitRule::where('plan_version_id', $this->version->id)->where('service_code', 'CONSULTA')->firstOrFail();
        $svc = app(BenefitService::class);
        $auth = $svc->reserve($sub, $rule, 1, $user, $this->unitA, 'k1')['authorization'];
        $svc->consume($auth, $user, $this->unitA);

        $reversal = $svc->reverse($auth->fresh(), $user, 'teste de estorno');

        $this->assertSame(-1, $reversal->quantity);
        $this->assertSame(2, BenefitLedgerEntry::where('authorization_id', $auth->id)->count());
    }

    public function test_tabelas_so_de_insercao_recusam_alterar_e_excluir(): void
    {
        foreach (['benefit_ledger', 'audit_logs', 'acceptance_evidence'] as $table) {
            $this->assertDenied(fn () => DB::table($table)->where('id', 0)->update(['id' => 0]), "UPDATE em {$table}");
            $this->assertDenied(fn () => DB::table($table)->where('id', 0)->delete(), "DELETE em {$table}");
        }

        $this->assertSame(0, DB::table('proposals')->where('id', 0)->update(['id' => 0]));
    }

    // Por último: no MySQL, DDL encerra a transação do teste se chegar a executar.
    public function test_usuario_da_aplicacao_nao_altera_a_estrutura(): void
    {
        $this->assertDenied(fn () => DB::statement('CREATE TABLE zz_app_grants (id INT)'), 'CREATE TABLE');
        $this->assertDenied(fn () => DB::statement('ALTER TABLE benefit_ledger DROP CHECK chk_benefit_ledger_entry'), 'ALTER TABLE');
    }

    protected function assertDenied(callable $statement, string $what): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            $this->assertSame(self::DENIED, $e->errorInfo[1] ?? null, "{$what}: falhou por outro motivo: {$e->getMessage()}");

            return;
        }
        $this->fail("{$what} deveria ser negado ao usuário da aplicação.");
    }
}
