<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\SubscriptionRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Conciliação de faturas e transações com o provedor (RF-06). Aceita duplicidade e ordem trocada:
 * uma notificação antiga nunca regride uma fatura já confirmada como paga.
 */
class InvoiceSyncService
{
    public function __construct(protected AuditLogger $audit, protected SubscriptionRecorder $recorder) {}

    /** @return array{invoice: Invoice, changed: bool, newly_paid: bool} */
    public function upsert(Subscription $subscription, BillData $bill, string $provider, bool $isInitial = false): array
    {
        return DB::transaction(function () use ($subscription, $bill, $provider, $isInitial) {
            $invoice = Invoice::where('provider', $provider)->where('external_id', $bill->externalId)->lockForUpdate()->first();
            $wasPaid = $invoice?->status === InvoiceStatus::Paid;

            if (! $invoice) {
                $isInitial = $isInitial || ! Invoice::where('subscription_id', $subscription->id)->where('is_initial', true)->exists();
                $invoice = new Invoice([
                    'subscription_id' => $subscription->id,
                    'provider' => $provider,
                    'external_id' => $bill->externalId,
                    'is_initial' => $isInitial,
                ]);
            }

            $incoming = InvoiceStatus::from($bill->status === 'pending' && $bill->paidCents() > 0 && $bill->paidCents() < $bill->amountCents ? 'partially_paid' : $bill->status);

            // Evento antigo não regride informação confirmada.
            $stale = $invoice->exists && $invoice->provider_updated_at && $bill->updatedAt && $bill->updatedAt->lt($invoice->provider_updated_at);
            $regression = $wasPaid && ! in_array($incoming, [InvoiceStatus::Paid, InvoiceStatus::Refunded, InvoiceStatus::Disputed], true);

            if (! $stale && ! $regression) {
                $invoice->fill([
                    'cycle' => $bill->cycle,
                    'period_start' => $bill->periodStart,
                    'period_end' => $bill->periodEnd,
                    'due_date' => $bill->dueDate,
                    'amount_cents' => $bill->amountCents,
                    'status' => $incoming,
                    'provider_status' => $bill->providerStatus,
                    'payment_url' => $bill->paymentUrl,
                    'provider_updated_at' => $bill->updatedAt,
                    'canceled_at' => $incoming === InvoiceStatus::Canceled ? ($invoice->canceled_at ?? now()) : $invoice->canceled_at,
                ]);
            }
            $invoice->last_synced_at = now();
            $changed = $invoice->isDirty(['status', 'amount_cents', 'due_date', 'payment_url']);
            $invoice->save();

            foreach ($bill->charges as $charge) {
                $this->syncCharge($invoice, $charge, $provider);
            }

            $paidCents = (int) Payment::where('invoice_id', $invoice->id)->where('status', PaymentStatus::Confirmed->value)->sum('amount_cents');
            $invoice->paid_cents = $paidCents;
            if ($invoice->status === InvoiceStatus::Paid || $paidCents >= $invoice->amount_cents && $paidCents > 0) {
                $invoice->paid_at ??= Payment::where('invoice_id', $invoice->id)->where('status', PaymentStatus::Confirmed->value)->min('paid_at');
            }
            $invoice->save();

            $newlyPaid = ! $wasPaid && $invoice->status === InvoiceStatus::Paid;
            if ($newlyPaid) {
                $this->recorder->event($subscription, 'invoice_paid', null, null, 'Fatura '.$invoice->external_id.' paga', [
                    'invoice_id' => $invoice->id, 'paid_at' => $invoice->paid_at?->toIso8601String(),
                ], 'provider', $invoice->paid_at);

                // Pagamento de contrato cancelado não reativa: encaminhar à análise financeira.
                if ($subscription->isTerminal()) {
                    $this->recorder->event($subscription, 'late_payment_after_cancellation', null, null,
                        'Pagamento recebido após cancelamento: análise financeira necessária', ['invoice_id' => $invoice->id], 'provider');
                }
            }
            if ($wasPaid && in_array($invoice->status, [InvoiceStatus::Refunded, InvoiceStatus::Disputed], true)) {
                $this->recorder->event($subscription, 'payment_reversed', null, null,
                    'Estorno ou contestação: recalcular elegibilidade e analisar atendimentos realizados', ['invoice_id' => $invoice->id], 'provider');
            }

            return ['invoice' => $invoice, 'changed' => $changed, 'newly_paid' => $newlyPaid];
        });
    }

    /** @param array{id: string, status: string, provider_status: string, amount_cents: int, paid_at: ?CarbonImmutable, method: ?string} $charge */
    protected function syncCharge(Invoice $invoice, array $charge, string $provider): void
    {
        $status = match ($charge['status']) {
            'paid' => PaymentStatus::Confirmed,
            'refunded' => PaymentStatus::Refunded,
            'disputed' => PaymentStatus::Disputed,
            default => null,
        };
        if (! $status) {
            return;
        }

        $payment = Payment::where('source', $provider)->where('external_transaction_id', $charge['id'])->lockForUpdate()->first();
        if (! $payment) {
            if (! $charge['paid_at']) {
                return;
            }
            Payment::create([
                'invoice_id' => $invoice->id,
                'source' => $provider,
                'external_transaction_id' => $charge['id'],
                'method' => $charge['method'],
                'amount_cents' => $charge['amount_cents'],
                'paid_at' => $charge['paid_at'],
                'status' => $status,
                'provider_status' => $charge['provider_status'],
            ]);

            return;
        }

        if ($payment->status !== $status) {
            $payment->forceFill(['status' => $status, 'provider_status' => $charge['provider_status']])->save();
        }
    }
}
