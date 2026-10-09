<?php

namespace App\Services\Financial;

use App\Enums\FinancialState;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\CustomerFinancialEvent;
use App\Models\CustomerFinancialStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Subscriptions\SubscriptionRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueio financeiro global por cliente (RF-14, seção 27). A decisão é sempre calculada a partir
 * das faturas atuais no banco central — o resumo gravado é apenas um espelho reconciliável.
 * Não existe liberação manual: nenhum perfil pode excepcionar a inadimplência.
 */
class FinancialStatusService
{
    public function __construct(
        protected SubscriptionRecorder $recorder,
        protected IntegrationOutbox $outbox,
        protected AuditLogger $audit,
    ) {}

    public function evaluate(Customer $customer): FinancialSnapshot
    {
        $subscriptionIds = Subscription::where('customer_id', $customer->id)->pluck('id');

        $overdue = Invoice::whereIn('subscription_id', $subscriptionIds)->overdue()
            ->get(['id', 'amount_cents', 'paid_cents', 'due_date']);

        $lastSynced = Invoice::whereIn('subscription_id', $subscriptionIds)->max('last_synced_at');

        if ($overdue->isNotEmpty()) {
            return new FinancialSnapshot(
                FinancialState::Delinquent,
                (int) $overdue->sum(fn ($i) => $i->amount_cents - $i->paid_cents),
                $overdue->pluck('id')->all(),
                $overdue->min(fn ($i) => $i->due_date->format('Y-m-d')),
                $lastSynced ? CarbonImmutable::parse($lastSynced) : null,
            );
        }

        // Informação incerta não autoriza serviço (seção 27.5): assinaturas ativas cobradas por provedor
        // externo sem conciliação dentro do limite de desatualização.
        $staleHours = config('planos.financial.max_staleness_hours');
        $stale = Subscription::where('customer_id', $customer->id)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::CancellationScheduled->value, SubscriptionStatus::Suspended->value])
            ->whereIn('billing_provider', ['vindi', 'fake'])
            ->get()
            ->filter(function (Subscription $s) use ($staleHours) {
                $last = Invoice::where('subscription_id', $s->id)->max('last_synced_at');

                return ! $last || CarbonImmutable::parse($last)->lt(now()->subHours($staleHours));
            });

        if ($stale->isNotEmpty()) {
            return new FinancialSnapshot(FinancialState::PendingVerification, 0, [], null,
                $lastSynced ? CarbonImmutable::parse($lastSynced) : null,
                'Conciliação desatualizada: '.$stale->pluck('number')->implode(', '));
        }

        return new FinancialSnapshot(FinancialState::Regular, 0, [], null, $lastSynced ? CarbonImmutable::parse($lastSynced) : null);
    }

    /**
     * Recalcula o espelho, registra bloqueio/desbloqueio e suspende/reativa assinaturas.
     * Pagar uma fatura não libera o cliente enquanto outra mantiver dívida vencida.
     */
    public function recompute(Customer $customer, string $source): FinancialSnapshot
    {
        return DB::transaction(function () use ($customer, $source) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->evaluate($customer);

            $row = CustomerFinancialStatus::find($customer->id);
            $previous = $row?->status;

            CustomerFinancialStatus::updateOrCreate(['customer_id' => $customer->id], [
                'status' => $snapshot->state,
                'overdue_cents' => $snapshot->overdueCents,
                'overdue_invoices' => count($snapshot->overdueInvoiceIds),
                'oldest_due_date' => $snapshot->oldestDueDate,
                'computed_at' => now(),
                'version' => ($row?->version ?? 0) + ($previous === $snapshot->state ? 0 : 1),
            ]);

            $becameDelinquent = $snapshot->isDelinquent() && $previous !== FinancialState::Delinquent;
            $leftDelinquency = ! $snapshot->isDelinquent() && $previous === FinancialState::Delinquent;

            if ($becameDelinquent) {
                CustomerFinancialEvent::create([
                    'customer_id' => $customer->id, 'event' => 'blocked',
                    'reason' => 'Dívida vencida de plano: bloqueio global de serviços e benefícios.',
                    'invoice_ids' => $snapshot->overdueInvoiceIds, 'source' => $source, 'occurred_at' => now(),
                ]);
                $this->audit->log('customer.financial_blocked', $customer, ['faturas' => $snapshot->overdueInvoiceIds], $source, 'system');
            }
            if ($leftDelinquency) {
                CustomerFinancialEvent::create([
                    'customer_id' => $customer->id, 'event' => 'unblocked',
                    'reason' => 'Todas as dívidas vencidas quitadas; elegibilidade reavaliada por assinatura.',
                    'invoice_ids' => [], 'source' => $source, 'occurred_at' => now(),
                ]);
                $this->audit->log('customer.financial_unblocked', $customer, null, $source, 'system');
            }

            $this->applyToSubscriptions($customer, $snapshot);

            return $snapshot;
        });
    }

    protected function applyToSubscriptions(Customer $customer, FinancialSnapshot $snapshot): void
    {
        $subscriptions = Subscription::where('customer_id', $customer->id)->lockForUpdate()->get();

        foreach ($subscriptions as $sub) {
            if ($snapshot->isDelinquent() && in_array($sub->status, [SubscriptionStatus::Active, SubscriptionStatus::CancellationScheduled], true)) {
                $this->recorder->transition($sub, SubscriptionStatus::Suspended, 'suspended', 'Dívida vencida do cliente: bloqueio global sem tolerância', [
                    'faturas' => $snapshot->overdueInvoiceIds,
                    'status_anterior' => $sub->getOriginal('status') instanceof SubscriptionStatus ? $sub->getOriginal('status')->value : $sub->getOriginal('status'),
                ]);
                $this->outbox->enqueue('simplesvet', 'suspend_planista', $sub, ['subscription_number' => $sub->number], 'suspend-'.now()->format('YmdHis'));
                $this->outbox->enqueue('tudoprapet', 'block_benefits', $customer, ['customer_id' => $customer->id], 'block-'.now()->format('YmdHis'));
            }

            if (! $snapshot->isDelinquent() && $sub->status === SubscriptionStatus::Suspended) {
                // Reativação exige eliminação dos impedimentos; contratos cancelados continuam cancelados.
                $previous = $sub->events()->where('event', 'suspended')->first()?->data['status_anterior'] ?? SubscriptionStatus::Active->value;
                $target = $previous === SubscriptionStatus::CancellationScheduled->value
                    ? SubscriptionStatus::CancellationScheduled : SubscriptionStatus::Active;
                $this->recorder->transition($sub, $target, 'reactivated', 'Regularização financeira confirmada');
                $this->outbox->enqueue('simplesvet', 'reactivate_planista', $sub, ['subscription_number' => $sub->number], 'reactivate-'.now()->format('YmdHis'));
                $this->outbox->enqueue('tudoprapet', 'enable_benefits', $customer, ['customer_id' => $customer->id], 'enable-'.now()->format('YmdHis'));
            }
        }
    }

    /** Rotina: a passagem do tempo cria dívida sem novo evento — revisa vencimentos. */
    public function reviewDueDates(): int
    {
        $count = 0;
        $customerIds = Subscription::query()
            ->whereIn('id', Invoice::query()->open()->where('due_date', '<', now(config('planos.business_timezone'))->toDateString())->select('subscription_id'))
            ->distinct()->pluck('customer_id');
        $alsoSuspended = Subscription::where('status', SubscriptionStatus::Suspended->value)->distinct()->pluck('customer_id');

        foreach ($customerIds->merge($alsoSuspended)->unique() as $id) {
            $this->recompute(Customer::find($id), 'revisão de vencimentos');
            $count++;
        }

        return $count;
    }
}
