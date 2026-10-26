<?php

namespace App\Services\Benefits;

use App\Enums\AuthorizationStatus;
use App\Models\Authorization;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitPeriod;
use App\Models\BenefitRule;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fluxo de uso: consultar elegibilidade → reservar → executar → confirmar consumo (seção 11).
 * Transações curtas com bloqueio na ordem cliente → assinatura → período; sem chamadas externas.
 * Duas recepções não consomem simultaneamente a última unidade.
 */
class BenefitService
{
    public function __construct(
        protected EligibilityService $eligibility,
        protected PeriodCalculator $periods,
        protected AuditLogger $audit,
    ) {}

    /** @return array{authorization: ?Authorization, decision: Decision} */
    public function reserve(Subscription $subscription, BenefitRule $rule, int $quantity, User $user, Franchise $franchise, string $idempotencyKey, ?string $notes = null): array
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantidade inválida.']);
        }

        if ($existing = Authorization::where('idempotency_key', $idempotencyKey)->first()) {
            return $this->replay($existing, $subscription, $rule, $quantity);
        }

        try {
            return DB::transaction(function () use ($subscription, $rule, $quantity, $user, $franchise, $idempotencyKey, $notes) {
                $sub = $this->lock($subscription);
                // Mesma chave em paralelo: a outra requisição gravou enquanto esta esperava o bloqueio.
                if ($existing = Authorization::where('idempotency_key', $idempotencyKey)->first()) {
                    return $this->replay($existing, $subscription, $rule, $quantity);
                }
                $now = CarbonImmutable::now();

                // Avaliação preliminar (vigência, financeiro, carência) antes de materializar o período.
                $decision = $this->eligibility->evaluate($sub, $rule, $sub->pet_id, $franchise->id, $quantity, $now);
                if (! $decision->allowed) {
                    return ['authorization' => null, 'decision' => $decision];
                }

                $period = $this->lockPeriod($sub, $rule, $now);
                $decision = $this->eligibility->evaluate($sub, $rule, $sub->pet_id, $franchise->id, $quantity, $now);
                if (! $decision->allowed) {
                    return ['authorization' => null, 'decision' => $decision];
                }

                $authorization = Authorization::create([
                    'uuid' => (string) Str::uuid(),
                    'subscription_id' => $sub->id,
                    'benefit_rule_id' => $rule->id,
                    'benefit_period_id' => $period->id,
                    'pet_id' => $sub->pet_id,
                    'quantity' => $quantity,
                    'status' => AuthorizationStatus::Reserved,
                    'expires_at' => $now->addHours(config('planos.benefits.reservation_ttl_hours')),
                    'servicing_franchise_id' => $franchise->id,
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'evaluation' => $decision->toArray(),
                    'notes' => $notes,
                ]);
                $this->audit->log('benefit.reserved', $authorization, ['servico' => $rule->service_code, 'quantidade' => $quantity]);

                return ['authorization' => $authorization, 'decision' => $decision];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Authorization::where('idempotency_key', $idempotencyKey)->firstOrFail();

            return ['authorization' => $existing, 'decision' => new Decision(true, 'AUTHORIZED', 'Reserva já registrada.')];
        }
    }

    /** Confirma execução: revalida elegibilidade na execução e impede consumo de reserva expirada. */
    public function consume(Authorization $authorization, User $user, Franchise $franchise, ?string $externalProcedureId = null): Decision
    {
        return DB::transaction(function () use ($authorization, $user, $franchise, $externalProcedureId) {
            $sub = $this->lock($authorization->subscription);
            BenefitPeriod::whereKey($authorization->benefit_period_id)->lockForUpdate()->first();
            $auth = Authorization::whereKey($authorization->id)->lockForUpdate()->firstOrFail();

            if ($auth->status === AuthorizationStatus::Consumed) {
                return new Decision(true, 'ALREADY_CONSUMED', 'Execução já confirmada.');
            }
            if ($auth->status !== AuthorizationStatus::Reserved) {
                return Decision::deny('AUTHORIZATION_NOT_RESERVED', 'Reserva não está mais válida ('.$auth->status->label().').', []);
            }
            if ($auth->expires_at->isPast()) {
                $auth->forceFill(['status' => AuthorizationStatus::Expired])->save();

                return Decision::deny('AUTHORIZATION_EXPIRED', 'Reserva expirada. Faça uma nova consulta de elegibilidade.', []);
            }

            $rule = $auth->benefitRule;
            $decision = $this->eligibility->evaluate($sub, $rule, $auth->pet_id, $franchise->id, $auth->quantity, now(), $auth->id);
            if (! $decision->allowed) {
                $this->audit->log('benefit.consume_denied', $auth, ['codigo' => $decision->code], $decision->message);

                return $decision;
            }

            BenefitLedgerEntry::create([
                'authorization_id' => $auth->id,
                'benefit_period_id' => $auth->benefit_period_id,
                'type' => BenefitLedgerEntry::TYPE_CONSUME,
                'quantity' => $auth->quantity,
                'dedupe_key' => 'consume:'.$auth->id,
                'servicing_franchise_id' => $franchise->id,
                'actor_id' => $user->id,
                'occurred_at' => now(),
            ]);
            $auth->forceFill([
                'status' => AuthorizationStatus::Consumed,
                'consumed_at' => now(),
                'consumed_by' => $user->id,
                'external_procedure_id' => $externalProcedureId,
            ])->save();
            $this->audit->log('benefit.consumed', $auth, ['servico' => $rule->service_code, 'quantidade' => $auth->quantity]);

            return $decision;
        });
    }

    public function release(Authorization $authorization, User $user, string $reason): Authorization
    {
        return DB::transaction(function () use ($authorization, $user, $reason) {
            $auth = Authorization::whereKey($authorization->id)->lockForUpdate()->firstOrFail();
            if ($auth->status !== AuthorizationStatus::Reserved) {
                throw ValidationException::withMessages(['authorization' => 'Somente reservas ativas podem ser canceladas.']);
            }
            $auth->forceFill([
                'status' => AuthorizationStatus::Released, 'released_at' => now(), 'released_by' => $user->id, 'release_reason' => $reason,
            ])->save();
            $this->audit->log('benefit.released', $auth, null, $reason);

            return $auth;
        });
    }

    /**
     * Estorno cria lançamento inverso vinculado ao original, no mesmo período (sem crédito ao período atual).
     */
    public function reverse(Authorization $authorization, User $user, string $reason): BenefitLedgerEntry
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Informe o motivo do estorno.']);
        }

        return DB::transaction(function () use ($authorization, $user, $reason) {
            $this->lock($authorization->subscription);
            $original = BenefitLedgerEntry::where('authorization_id', $authorization->id)
                ->where('type', BenefitLedgerEntry::TYPE_CONSUME)->lockForUpdate()->first();
            if (! $original) {
                throw ValidationException::withMessages(['authorization' => 'Não há consumo confirmado para estornar.']);
            }
            // Leitura com bloqueio: a leitura comum enxerga o banco de antes da espera e não vê um estorno paralelo.
            if (BenefitLedgerEntry::where('original_entry_id', $original->id)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['authorization' => 'Esta utilização já foi estornada.']);
            }

            $entry = BenefitLedgerEntry::create([
                'authorization_id' => $authorization->id,
                'benefit_period_id' => $original->benefit_period_id,
                'type' => BenefitLedgerEntry::TYPE_REVERSAL,
                'quantity' => -$original->quantity,
                'original_entry_id' => $original->id,
                'dedupe_key' => 'reversal:'.$original->id,
                'servicing_franchise_id' => $original->servicing_franchise_id,
                'actor_id' => $user->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            $this->audit->log('benefit.reversed', $authorization, ['lancamento_original' => $original->id], $reason);

            return $entry;
        });
    }

    public function expireReservations(): int
    {
        return Authorization::where('status', AuthorizationStatus::Reserved->value)
            ->where('expires_at', '<=', now())
            ->update(['status' => AuthorizationStatus::Expired->value, 'updated_at' => now()]);
    }

    /** @return array{authorization: Authorization, decision: Decision} */
    protected function replay(Authorization $existing, Subscription $subscription, BenefitRule $rule, int $quantity): array
    {
        if ($existing->subscription_id !== $subscription->id || $existing->benefit_rule_id !== $rule->id || $existing->quantity !== $quantity) {
            throw new HttpException(409, 'Chave de requisição já utilizada com dados diferentes.');
        }

        return ['authorization' => $existing, 'decision' => new Decision(true, 'AUTHORIZED', 'Reserva já registrada.')];
    }

    protected function lock(Subscription $subscription): Subscription
    {
        Customer::whereKey($subscription->customer_id)->lockForUpdate()->first();

        return Subscription::with('customer', 'contract')->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
    }

    protected function lockPeriod(Subscription $sub, BenefitRule $rule, CarbonImmutable $at): BenefitPeriod
    {
        [$start, $end] = $this->periods->periodFor($rule, $sub->effective_start_at, $at);
        $attrs = ['subscription_id' => $sub->id, 'benefit_rule_id' => $rule->id, 'starts_at' => $start];

        $period = BenefitPeriod::where($attrs)->lockForUpdate()->first();
        if (! $period) {
            try {
                DB::transaction(fn () => BenefitPeriod::create($attrs + ['ends_at' => $end, 'limit_snapshot' => $rule->quantity_limit]));
            } catch (UniqueConstraintViolationException) {
                // Criado em paralelo por outra recepção: segue para o bloqueio.
            }
            $period = BenefitPeriod::where($attrs)->lockForUpdate()->firstOrFail();
        }

        return $period;
    }
}
