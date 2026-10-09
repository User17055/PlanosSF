<?php

namespace App\Services\Benefits;

use App\Enums\AuthorizationStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Authorization;
use App\Models\BenefitLedgerEntry;
use App\Models\BenefitPeriod;
use App\Models\BenefitRule;
use App\Models\Subscription;
use App\Services\Financial\FinancialStatusService;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Serviço central de elegibilidade usado por todas as telas e endpoints que registram atendimento
 * ou benefício (seções 11 e 27.4). A cor na interface é informativa; a proteção real é esta.
 */
class EligibilityService
{
    public function __construct(
        protected PeriodCalculator $periods,
        protected FinancialStatusService $financial,
    ) {}

    public function evaluate(
        Subscription $subscription,
        BenefitRule $rule,
        int $petId,
        int $servicingFranchiseId,
        int $quantity = 1,
        ?CarbonInterface $at = null,
        ?int $excludeAuthorizationId = null,
    ): Decision {
        $at = CarbonImmutable::instance($at ?? now());
        $subscription->loadMissing('customer', 'contract');
        $checks = [];

        if ($subscription->pet_id !== $petId) {
            return Decision::deny('PET_MISMATCH', 'Este plano não cobre o pet informado.', $checks);
        }
        $checks[] = ['rule' => 'pet', 'ok' => true];

        if (! $subscription->contract->hasValidFinalDocument()) {
            return Decision::deny('CONTRACT_INVALID', 'Contrato final ausente ou não validado.', $checks);
        }
        $checks[] = ['rule' => 'contrato', 'ok' => true];

        $financial = $this->financial->evaluate($subscription->customer);

        if (! $subscription->isActive() || ! $subscription->effective_start_at || $subscription->effective_start_at->gt($at)
            || ($subscription->end_at && $subscription->end_at->lte($at))) {
            // Suspensão por dívida: a mensagem informa a inadimplência e a orientação de regularização.
            if ($subscription->status === SubscriptionStatus::Suspended && $financial->isDelinquent()) {
                return Decision::deny('CUSTOMER_DELINQUENT', $financial->blockMessage(), $checks);
            }

            return Decision::deny('SUBSCRIPTION_NOT_ACTIVE', 'Assinatura não vigente ('.$subscription->status->label().').', $checks);
        }
        $checks[] = ['rule' => 'vigencia', 'ok' => true];

        if (! $financial->isRegular()) {
            return Decision::deny(
                $financial->isDelinquent() ? 'CUSTOMER_DELINQUENT' : 'FINANCIAL_VERIFICATION_PENDING',
                $financial->blockMessage(), $checks,
            );
        }
        $checks[] = ['rule' => 'financeiro', 'ok' => true];

        if ($rule->plan_version_id !== $subscription->plan_version_id) {
            return Decision::deny('BENEFIT_NOT_COVERED', 'Benefício não coberto pela versão contratada.', $checks);
        }
        $checks[] = ['rule' => 'cobertura', 'ok' => true];

        if (! $rule->allowsFranchise($servicingFranchiseId)) {
            return Decision::deny('UNIT_NOT_ALLOWED', 'Benefício não disponível nesta unidade.', $checks);
        }
        $checks[] = ['rule' => 'unidade', 'ok' => true];

        $availableFrom = $this->periods->availableFrom($rule, $subscription->effective_start_at);
        if ($at->lt($availableFrom)) {
            return Decision::deny('BENEFIT_WAITING_PERIOD', 'Carência até '.BusinessTime::formatDateTime($availableFrom).'.', $checks, $availableFrom);
        }
        $checks[] = ['rule' => 'carencia', 'ok' => true, 'detail' => 'liberado desde '.BusinessTime::formatDateTime($availableFrom)];

        if ($rule->min_interval_days) {
            $last = BenefitLedgerEntry::query()
                ->join('authorizations', 'authorizations.id', '=', 'benefit_ledger.authorization_id')
                ->where('authorizations.subscription_id', $subscription->id)
                ->where('authorizations.benefit_rule_id', $rule->id)
                ->where('benefit_ledger.type', BenefitLedgerEntry::TYPE_CONSUME)
                ->whereNotExists(fn ($q) => $q->from('benefit_ledger as r')->whereColumn('r.original_entry_id', 'benefit_ledger.id'))
                ->max('benefit_ledger.occurred_at');
            if ($last) {
                $next = CarbonImmutable::parse($last)->addDays($rule->min_interval_days);
                if ($at->lt($next)) {
                    return Decision::deny('BENEFIT_MIN_INTERVAL', 'Intervalo mínimo entre utilizações: disponível a partir de '.BusinessTime::formatDateTime($next).'.', $checks, $next);
                }
            }
            $checks[] = ['rule' => 'frequencia', 'ok' => true];
        }

        [$start, $end] = $this->periods->periodFor($rule, $subscription->effective_start_at, $at);

        if ($rule->isUnlimited()) {
            $checks[] = ['rule' => 'saldo', 'ok' => true, 'detail' => 'ilimitado'];

            return Decision::allow(null, null, $checks, $start, $end);
        }

        $used = $this->usage($subscription->id, $rule, $start, $at, $excludeAuthorizationId);
        $available = $rule->quantity_limit - $used;
        if ($available < $quantity) {
            return Decision::deny('BENEFIT_LIMIT_REACHED', 'Limite do período atingido ('.$rule->quantity_limit.' '.$rule->unit.').', $checks, $end, $rule->quantity_limit, max(0, $available));
        }
        $checks[] = ['rule' => 'saldo', 'ok' => true, 'detail' => "{$available} de {$rule->quantity_limit}"];

        if ($rule->coverage_group && $rule->group_limit !== null) {
            $groupRules = BenefitRule::where('plan_version_id', $rule->plan_version_id)->where('coverage_group', $rule->coverage_group)->get();
            $groupUsed = 0;
            foreach ($groupRules as $gr) {
                [$gs] = $this->periods->periodFor($gr, $subscription->effective_start_at, $at);
                $groupUsed += $this->usage($subscription->id, $gr, $gs, $at, $excludeAuthorizationId);
            }
            if ($rule->group_limit - $groupUsed < $quantity) {
                return Decision::deny('BENEFIT_GROUP_LIMIT_REACHED', 'Limite compartilhado do grupo "'.$rule->coverage_group.'" atingido.', $checks, $end, $rule->group_limit, max(0, $rule->group_limit - $groupUsed));
            }
            $checks[] = ['rule' => 'grupo', 'ok' => true];
        }

        if ($rule->copay_cents) {
            $checks[] = ['rule' => 'coparticipacao', 'ok' => true, 'detail' => 'Coparticipação de '.Money::format($rule->copay_cents).' ('.$rule->copay_stage.', recebe: '.$rule->copay_receiver.')'];
        }

        return Decision::allow($rule->quantity_limit, $available, $checks, $start, $end);
    }

    /** Saldo disponível = limite − consumos confirmados − reservas válidas. */
    public function usage(int $subscriptionId, BenefitRule $rule, CarbonInterface $periodStart, CarbonInterface $at, ?int $excludeAuthorizationId = null): int
    {
        $period = BenefitPeriod::where('subscription_id', $subscriptionId)->where('benefit_rule_id', $rule->id)
            ->where('starts_at', $periodStart)->first();
        if (! $period) {
            return 0;
        }

        $windowStart = $this->periods->rollingWindowStart($rule, $at);
        $consumed = (int) BenefitLedgerEntry::where('benefit_period_id', $period->id)
            ->when($windowStart, fn ($q) => $q->where('occurred_at', '>', $windowStart))
            ->sum('quantity');

        $reserved = (int) Authorization::where('benefit_period_id', $period->id)
            ->where('status', AuthorizationStatus::Reserved->value)
            ->where('expires_at', '>', now())
            ->when($excludeAuthorizationId, fn ($q) => $q->where('id', '!=', $excludeAuthorizationId))
            ->sum('quantity');

        return $consumed + $reserved;
    }
}
