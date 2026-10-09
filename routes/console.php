<?php

use App\Models\Franchise;
use App\Models\FranchiseUser;
use App\Models\Role;
use App\Models\User;
use App\Services\Benefits\BenefitService;
use App\Services\Billing\ReconciliationService;
use App\Services\Financial\FinancialStatusService;
use App\Services\Integrations\IntegrationRunner;
use App\Services\Proposals\ProposalService;
use App\Services\Subscriptions\ActivationService;
use App\Services\Subscriptions\CancellationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 * Rotinas da seção 23. O agendador apenas dispara; cada rotina registra sua última execução
 * para que o painel alerte quando o agendador parar.
 */
$heartbeat = function (string $name, string $result): void {
    DB::table('scheduler_heartbeats')->updateOrInsert(['name' => $name], ['last_run_at' => now(), 'last_result' => mb_substr($result, 0, 500)]);
};

Artisan::command('planos:expire-proposals', function (ProposalService $s) use ($heartbeat) {
    $n = $s->expireStale();
    $heartbeat('expire-proposals', "{$n} propostas expiradas");
    $this->info("{$n} propostas expiradas");
})->purpose('Expira propostas e links vencidos');

Artisan::command('planos:expire-reservations', function (BenefitService $s) use ($heartbeat) {
    $n = $s->expireReservations();
    $heartbeat('expire-reservations', "{$n} reservas expiradas");
    $this->info("{$n} reservas expiradas");
})->purpose('Libera reservas de benefício vencidas');

Artisan::command('planos:activate-pending', function (ActivationService $s) use ($heartbeat) {
    $n = $s->activatePending();
    $heartbeat('activate-pending', "{$n} ativações");
    $this->info("{$n} assinaturas ativadas");
})->purpose('Ativa assinaturas com requisitos atendidos (inclui vigência futura atingida)');

Artisan::command('planos:execute-cancellations', function (CancellationService $s) use ($heartbeat) {
    $n = $s->executeDue();
    $heartbeat('execute-cancellations', "{$n} encerramentos");
    $this->info("{$n} encerramentos executados");
})->purpose('Executa cancelamentos agendados');

Artisan::command('planos:review-due-dates', function (FinancialStatusService $s) use ($heartbeat) {
    $n = $s->reviewDueDates();
    $heartbeat('review-due-dates', "{$n} clientes recalculados");
    $this->info("{$n} clientes recalculados");
})->purpose('Revisa vencimentos e aplica o bloqueio financeiro global');

Artisan::command('planos:reconcile {--pending : Somente primeiras cobranças pendentes}', function (ReconciliationService $s) use ($heartbeat) {
    $r = $s->reconcileAll((bool) $this->option('pending'));
    $name = $this->option('pending') ? 'reconcile-pending' : 'reconcile-all';
    $heartbeat($name, "{$r['ok']} ok, ".count($r['failed']).' falhas');
    $this->info("{$r['ok']} assinaturas conciliadas");
    foreach ($r['failed'] as $f) {
        $this->warn($f);
    }
})->purpose('Concilia faturas com o provedor de cobrança');

Artisan::command('planos:dispatch-integrations', function (IntegrationRunner $s) use ($heartbeat) {
    $n = $s->dispatchDue();
    $heartbeat('dispatch-integrations', "{$n} execuções");
})->purpose('Despacha a outbox de integrações automáticas');

Artisan::command('planos:create-user {email} {name} {role} {franchise=CENTRAL}', function () {
    $role = Role::where('key', $this->argument('role'))->firstOrFail();
    $franchise = Franchise::where('code', $this->argument('franchise'))->firstOrFail();
    $password = Str::password(16, symbols: false);
    $user = User::firstOrCreate(
        ['email' => strtolower($this->argument('email'))],
        ['name' => $this->argument('name'), 'password' => $password, 'is_active' => true],
    );
    FranchiseUser::updateOrCreate(
        ['user_id' => $user->id, 'franchise_id' => $franchise->id],
        ['role_id' => $role->id, 'is_active' => true],
    );
    // A senha só é impressa quando o usuário é criado agora; não é registrada em log.
    $this->line($user->wasRecentlyCreated ? "{$user->email}\t{$password}" : "{$user->email}\t(já existia; senha mantida)");
})->purpose('Cria usuário com vínculo e perfil (gera senha aleatória)');

Artisan::command('planos:heartbeat', function () use ($heartbeat) {
    $heartbeat('scheduler', 'ok');
})->purpose('Sinal de vida do agendador');

Schedule::command('planos:heartbeat')->everyMinute();
Schedule::command('planos:dispatch-integrations')->everyMinute()->withoutOverlapping(10);
Schedule::command('planos:expire-reservations')->everyTenMinutes()->withoutOverlapping();
Schedule::command('planos:reconcile --pending')->everyTenMinutes()->withoutOverlapping(30);
Schedule::command('planos:activate-pending')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('planos:execute-cancellations')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('planos:expire-proposals')->hourly()->withoutOverlapping();
Schedule::command('planos:review-due-dates')->hourly()->withoutOverlapping();
Schedule::command('planos:review-due-dates')->dailyAt('00:05')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('planos:reconcile')->dailyAt('03:00')->timezone('America/Sao_Paulo')->withoutOverlapping(120);
