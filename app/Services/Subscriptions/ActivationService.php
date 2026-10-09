<?php

namespace App\Services\Subscriptions;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProposalStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\PetEligibility;
use App\Services\Financial\FinancialStatusService;
use App\Services\Integrations\IntegrationOutbox;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Serviço único de ativação (seção 21): a entrada em "ativa" acontece somente aqui.
 * Cadastro completo e elegível + contrato final válido + primeiro pagamento integral confirmado
 * + assinatura não cancelada + data de início atingida.
 */
class ActivationService
{
    public function __construct(
        protected SubscriptionRecorder $recorder,
        protected PetEligibility $eligibility,
        protected FinancialStatusService $financial,
        protected IntegrationOutbox $outbox,
        protected AuditLogger $audit,
    ) {}

    /** @return array<int, Impediment> */
    public function impediments(Subscription $subscription): array
    {
        $subscription->loadMissing('customer', 'pet', 'contract', 'planVersion');
        $list = [];

        if ($subscription->isTerminal()) {
            $list[] = Impediment::Terminated;
        }
        if (! $subscription->customer->isComplete()) {
            $list[] = Impediment::CustomerIncomplete;
        }
        if (! $this->eligibility->isEligible($subscription->planVersion, $subscription->pet) && ! $subscription->activated_at) {
            $list[] = Impediment::PetIneligible;
        }

        $contract = $subscription->contract;
        if ($contract->document_status === DocumentStatus::Rejected) {
            $list[] = Impediment::DocumentRejected;
        } elseif ($contract->document_status === DocumentStatus::AwaitingReview) {
            $list[] = Impediment::DocumentAwaitingReview;
        } elseif (! $contract->hasValidFinalDocument()) {
            $list[] = Impediment::ContractMissing;
        }

        if (! $this->firstPaymentConfirmedAt($subscription)) {
            $list[] = Impediment::FirstPaymentPending;
        }
        if ($this->financial->evaluate($subscription->customer)->isDelinquent()) {
            $list[] = Impediment::Delinquent;
        }
        if ($subscription->desired_start_date && $this->desiredStart($subscription)->isFuture()) {
            $list[] = Impediment::FutureStart;
        }

        return $list;
    }

    public function firstPaymentConfirmedAt(Subscription $subscription): ?CarbonImmutable
    {
        $invoice = Invoice::where('subscription_id', $subscription->id)->where('is_initial', true)->first();
        if (! $invoice || $invoice->status !== InvoiceStatus::Paid || $invoice->paid_cents < $invoice->amount_cents) {
            return null;
        }

        return $invoice->paid_at ? CarbonImmutable::instance($invoice->paid_at) : null;
    }

    public function tryActivate(Subscription $subscription, string $trigger): bool
    {
        $activated = DB::transaction(function () use ($subscription, $trigger) {
            // Ordem de bloqueio: cliente → assinatura (a mesma do processamento financeiro e do consumo).
            Customer::whereKey($subscription->customer_id)->lockForUpdate()->first();
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($subscription->status !== SubscriptionStatus::AwaitingRegularization || $subscription->activated_at) {
                return false;
            }

            $blocking = array_filter($this->impediments($subscription), fn (Impediment $i) => $i->blocksActivation());
            if ($blocking !== []) {
                return false;
            }

            $paidAt = $this->firstPaymentConfirmedAt($subscription);
            $candidates = [
                CarbonImmutable::instance($subscription->contract->validated_at ?? now()),
                $paidAt,
            ];
            if ($subscription->desired_start_date) {
                $candidates[] = $this->desiredStart($subscription);
            }
            $effectiveStart = collect($candidates)->filter()->max();

            $subscription->forceFill([
                'activated_at' => now(),
                'effective_start_at' => $effectiveStart,
                'card_number' => $subscription->card_number ?? $this->cardNumber($subscription),
            ]);
            $this->recorder->transition($subscription, SubscriptionStatus::Active, 'activated', $trigger, [
                'effective_start_at' => $effectiveStart->toIso8601String(),
                'paid_at' => $paidAt?->toIso8601String(),
                'contract_validated_at' => $subscription->contract->validated_at?->toIso8601String(),
            ]);

            $subscription->proposal->forceFill(['status' => ProposalStatus::Completed, 'completed_at' => now()])->save();

            $this->outbox->enqueue('simplesvet', 'link_planista', $subscription, [
                'subscription_number' => $subscription->number,
                'plan_code' => $subscription->planVersion->plan->code,
                'status' => 'ativo',
            ], 'activation');
            $this->outbox->enqueue('tudoprapet', 'create_or_link_account', $subscription->customer, [
                'customer_id' => $subscription->customer_id,
                'subscription_number' => $subscription->number,
            ], 'customer');

            $this->audit->log('subscription.activated', $subscription, ['inicio_vigencia' => $effectiveStart->toIso8601String()], $trigger, 'system');

            return true;
        });

        if ($activated) {
            $this->financial->recompute($subscription->customer()->first(), 'ativação');
        }

        return $activated;
    }

    /** Rotina agendada: ativa assinaturas cuja vigência futura foi atingida. */
    public function activatePending(): int
    {
        $count = 0;
        Subscription::where('status', SubscriptionStatus::AwaitingRegularization->value)->whereNull('activated_at')
            ->chunkById(100, function ($subs) use (&$count) {
                foreach ($subs as $sub) {
                    $count += $this->tryActivate($sub, 'Verificação periódica') ? 1 : 0;
                }
            });

        return $count;
    }

    protected function desiredStart(Subscription $subscription): CarbonImmutable
    {
        return CarbonImmutable::parse($subscription->desired_start_date->format('Y-m-d'), BusinessTime::tz())->startOfDay()->utc();
    }

    protected function cardNumber(Subscription $subscription): string
    {
        return 'SF'.BusinessTime::now()->format('y').str_pad((string) $subscription->id, 7, '0', STR_PAD_LEFT);
    }
}
