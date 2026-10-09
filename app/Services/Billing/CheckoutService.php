<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ProposalChannel;
use App\Models\ExternalMapping;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Subscriptions\SubscriptionRecorder;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST /subscriptions/{id}/checkout — obtém ou recupera a mesma cobrança inicial, sem duplicar assinatura.
 * Clicar em "pagar" várias vezes nunca cria nova assinatura no provedor.
 */
class CheckoutService
{
    public function __construct(
        protected BillingGateway $gateway,
        protected InvoiceSyncService $sync,
        protected SubscriptionRecorder $recorder,
        protected AuditLogger $audit,
    ) {}

    public function initialInvoice(Subscription $subscription): ?Invoice
    {
        return Invoice::where('subscription_id', $subscription->id)->where('is_initial', true)->first();
    }

    public function start(Subscription $subscription): Invoice
    {
        if ($subscription->isTerminal()) {
            throw new HttpException(409, 'Assinatura encerrada.');
        }
        $subscription->loadMissing('proposal');

        // Presencial: o recebimento depende da decisão pendente (seção 7.3); não gera link nem cobrança no provedor.
        if ($subscription->proposal->channel === ProposalChannel::InPerson) {
            return $this->manualInitialInvoice($subscription);
        }

        $claim = DB::transaction(function () use ($subscription) {
            $sub = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($sub->billing_status === 'created') {
                return ['done' => true];
            }
            if ($sub->billing_status === 'requesting' && $sub->billing_requested_at?->gt(now()->subMinutes(2))) {
                throw new HttpException(409, 'A cobrança está sendo criada. Aguarde alguns segundos e tente novamente.');
            }
            $uncertain = $sub->billing_status === 'requesting' || $sub->billing_status === 'uncertain';
            $sub->forceFill([
                'billing_status' => 'requesting',
                'billing_requested_at' => now(),
                'billing_provider' => $this->gateway->name(),
            ])->save();

            return ['done' => false, 'uncertain' => $uncertain];
        });

        if ($claim['done']) {
            return $this->initialInvoice($subscription) ?? throw new HttpException(409, 'Cobrança inicial não localizada; acione o financeiro.');
        }

        try {
            // Resultado incerto anterior: consultar antes de criar de novo.
            $result = $claim['uncertain'] ? $this->gateway->findSubscriptionByCode($subscription->number) : null;

            if (! $result) {
                $customerExternal = ExternalMapping::externalId($this->gateway->name(), $subscription->customer)
                    ?? $this->gateway->ensureCustomer($subscription->customer);
                ExternalMapping::link($this->gateway->name(), $subscription->customer, $customerExternal);
                $result = $this->gateway->createSubscription($subscription, $customerExternal);
            }
        } catch (BillingException $e) {
            $subscription->forceFill([
                'billing_status' => $e->kind === BillingException::KIND_UNCERTAIN ? 'uncertain' : 'error',
            ])->save();
            $this->recorder->event($subscription, 'billing_error', null, null, $e->getMessage(), ['tipo' => $e->kind]);
            throw new HttpException(503, $e->kind === BillingException::KIND_UNCERTAIN
                ? 'Não recebemos confirmação do provedor de pagamento. Tente novamente em instantes; não haverá cobrança duplicada.'
                : 'Não foi possível iniciar o pagamento agora. Tente novamente mais tarde.');
        }

        return DB::transaction(function () use ($subscription, $result) {
            $sub = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            ExternalMapping::link($this->gateway->name(), $sub, $result['subscription_id']);

            $bill = $result['bill'] ?? ($this->gateway->listBills($result['subscription_id'])[0] ?? null);
            if (! $bill) {
                throw new HttpException(503, 'O provedor ainda não emitiu a cobrança inicial. Tente novamente em instantes.');
            }
            $invoice = $this->sync->upsert($sub, $bill, $this->gateway->name(), true)['invoice'];

            $sub->forceFill(['billing_status' => 'created'])->save();
            $this->recorder->event($sub, 'billing_created', null, null, 'Cobrança recorrente criada no provedor', [
                'provider' => $this->gateway->name(), 'invoice_id' => $invoice->id,
            ]);
            $this->audit->log('subscription.billing_created', $sub, ['provedor' => $this->gateway->name()], null, 'system');

            return $invoice;
        });
    }

    protected function manualInitialInvoice(Subscription $subscription): Invoice
    {
        return DB::transaction(function () use ($subscription) {
            $sub = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if ($existing = $this->initialInvoice($sub)) {
                return $existing;
            }
            $invoice = Invoice::create([
                'subscription_id' => $sub->id,
                'provider' => 'manual',
                'external_id' => 'MAN-'.$sub->number.'-1',
                'is_initial' => true,
                'cycle' => 1,
                'due_date' => BusinessTime::today()->format('Y-m-d'),
                'amount_cents' => $sub->proposal->price_cents + $sub->proposal->enrollment_fee_cents,
                'status' => InvoiceStatus::Pending,
                'last_synced_at' => now(),
            ]);
            $sub->forceFill(['billing_status' => 'pending_decision', 'billing_provider' => 'manual'])->save();
            $this->recorder->event($sub, 'manual_initial_invoice', null, null,
                'Primeira parcela presencial registrada localmente; reflexo na Vindi depende de decisão pendente.');

            return $invoice;
        });
    }
}
