<?php

namespace App\Services\Subscriptions;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Models\CancellationRequest;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationOutbox;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancelamento: separa cancelamento imediato de encerramento ao fim do período pago (seção 10).
 */
class CancellationService
{
    public function __construct(
        protected SubscriptionRecorder $recorder,
        protected IntegrationOutbox $outbox,
        protected AuditLogger $audit,
    ) {}

    /**
     * Resumo exibido ao operador antes de concluir: encerramento, período pago, débitos e cobranças futuras.
     *
     * @return array<string, mixed>
     */
    public function summary(Subscription $subscription): array
    {
        $invoices = Invoice::where('subscription_id', $subscription->id)->orderBy('due_date')->get();
        $lastPaid = $invoices->where('status', InvoiceStatus::Paid)->sortByDesc('period_end')->first();
        $overdue = $invoices->filter->isOverdue();
        $future = $invoices->filter(fn (Invoice $i) => in_array($i->status, [InvoiceStatus::Pending, InvoiceStatus::PartiallyPaid], true) && ! $i->isOverdue());

        $lastCoveredEnd = $lastPaid?->period_end;
        $endOfPeriod = $lastCoveredEnd
            ? CarbonImmutable::parse($lastCoveredEnd->format('Y-m-d'), BusinessTime::tz())->addDay()->startOfDay()
            : BusinessTime::now();

        return [
            'last_covered_period_end' => $lastCoveredEnd?->format('Y-m-d'),
            'end_of_period_at' => $endOfPeriod,
            'overdue' => $overdue->values(),
            'overdue_cents' => $overdue->sum(fn ($i) => $i->outstandingCents()),
            'future' => $future->values(),
        ];
    }

    public function request(Subscription $subscription, string $type, string $reason, ?User $user, string $via = 'staff'): CancellationRequest
    {
        if (! in_array($type, [CancellationRequest::TYPE_IMMEDIATE, CancellationRequest::TYPE_END_OF_PERIOD], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de cancelamento inválido.']);
        }

        return DB::transaction(function () use ($subscription, $type, $reason, $user, $via) {
            $sub = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($sub->isTerminal()) {
                throw ValidationException::withMessages(['type' => 'Assinatura já encerrada.']);
            }
            $summary = $this->summary($sub);
            $effectiveAt = $type === CancellationRequest::TYPE_IMMEDIATE ? now() : $summary['end_of_period_at'];

            $request = CancellationRequest::create([
                'subscription_id' => $sub->id,
                'type' => $type,
                'reason' => $reason,
                'requested_via' => $via,
                'requested_by' => $user?->id,
                'effective_at' => $effectiveAt,
                'last_covered_period_end' => $summary['last_covered_period_end'],
                'status' => $via === 'customer' ? 'requested' : 'approved',
                'provider_status' => $sub->billing_provider && $sub->billing_provider !== 'manual' ? 'pending' : 'not_applicable',
            ]);

            if ($via !== 'customer') {
                $this->execute($sub, $request);
            } else {
                $this->recorder->event($sub, 'cancellation_requested_by_customer', null, null, $reason);
            }
            $this->audit->log('subscription.cancellation_requested', $sub, ['tipo' => $type, 'via' => $via], $reason, $via === 'customer' ? 'customer' : 'user');

            return $request;
        });
    }

    public function approveCustomerRequest(CancellationRequest $request, User $user): void
    {
        DB::transaction(function () use ($request, $user) {
            $request = CancellationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== 'requested') {
                throw ValidationException::withMessages(['request' => 'Solicitação já tratada.']);
            }
            $request->forceFill(['status' => 'approved', 'requested_by' => $request->requested_by ?? $user->id])->save();
            $this->execute(Subscription::whereKey($request->subscription_id)->lockForUpdate()->firstOrFail(), $request);
            $this->audit->log('subscription.cancellation_approved', $request->subscription, null, $request->reason);
        });
    }

    protected function execute(Subscription $sub, CancellationRequest $request): void
    {
        if ($request->provider_status === 'pending') {
            // Solicitação ao provedor acompanhada até confirmação; falha gera "cancelamento financeiro pendente".
            $this->outbox->enqueue('vindi', 'cancel_subscription', $sub, ['subscription_number' => $sub->number, 'cancellation_request_id' => $request->id], 'cancel-'.$request->id);
        }

        if ($request->effective_at->lte(now())) {
            $this->finish($sub, $request);
        } else {
            $this->recorder->transition($sub, SubscriptionStatus::CancellationScheduled, 'cancellation_scheduled', $request->reason, [
                'effective_at' => $request->effective_at->toIso8601String(),
            ]);
            $sub->forceFill(['end_at' => $request->effective_at])->save();
            $request->forceFill(['status' => 'scheduled'])->save();
        }
    }

    protected function finish(Subscription $sub, CancellationRequest $request): void
    {
        $sub->forceFill(['canceled_at' => now(), 'end_at' => $sub->end_at && $sub->end_at->lte(now()) ? $sub->end_at : now()]);
        $to = $request->type === CancellationRequest::TYPE_END_OF_PERIOD ? SubscriptionStatus::Ended : SubscriptionStatus::Canceled;
        $this->recorder->transition($sub, $to, $to === SubscriptionStatus::Ended ? 'ended' : 'canceled', $request->reason);
        $request->forceFill(['status' => 'executed', 'executed_at' => now()])->save();

        $this->outbox->enqueue('simplesvet', 'unlink_planista', $sub, ['subscription_number' => $sub->number], 'end-'.$request->id);
        $this->outbox->enqueue('tudoprapet', 'review_benefits', $sub->customer, ['customer_id' => $sub->customer_id, 'motivo' => 'plano encerrado'], 'end-'.$sub->id);
    }

    /** Rotina: executa encerramentos agendados cuja data efetiva foi atingida. */
    public function executeDue(): int
    {
        $count = 0;
        CancellationRequest::where('status', 'scheduled')->where('effective_at', '<=', now())->each(function (CancellationRequest $r) use (&$count) {
            DB::transaction(function () use ($r) {
                $sub = Subscription::whereKey($r->subscription_id)->lockForUpdate()->firstOrFail();
                if (! $sub->isTerminal()) {
                    $this->finish($sub, $r);
                }
            });
            $count++;
        });

        return $count;
    }
}
