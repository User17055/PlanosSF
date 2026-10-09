<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\ExternalMapping;
use App\Models\Subscription;
use App\Models\WebhookInbox;
use App\Services\Financial\FinancialStatusService;
use App\Services\Integrations\IntegrationOutbox;
use App\Services\Subscriptions\ActivationService;
use App\Services\Subscriptions\SubscriptionRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Processamento de eventos do provedor e conciliação periódica (seção 12.1): recupera eventos perdidos,
 * consulta a situação atual na API e recalcula elegibilidade.
 */
class ReconciliationService
{
    public function __construct(
        protected BillingGateway $gateway,
        protected InvoiceSyncService $sync,
        protected FinancialStatusService $financial,
        protected ActivationService $activation,
        protected SubscriptionRecorder $recorder,
        protected IntegrationOutbox $outbox,
    ) {}

    public function processInbox(WebhookInbox $inbox): string
    {
        $payload = $inbox->payload;
        $type = $inbox->event_type;
        $data = $payload['event']['data'] ?? [];

        if ($type === 'test') {
            return 'evento de teste';
        }

        $billId = $data['bill']['id'] ?? $data['charge']['bill']['id'] ?? null;
        $subExternal = $data['subscription']['id'] ?? $data['bill']['subscription']['id'] ?? null;

        if ($billId) {
            $bill = $this->gateway->getBill((string) $billId);
            $subscription = $this->subscriptionFor($bill->subscriptionExternalId ?? ($subExternal ? (string) $subExternal : null));
            if (! $subscription) {
                return 'ignorado: assinatura desconhecida';
            }
            $this->applyBill($subscription, $bill);

            return 'fatura '.$bill->externalId.' conciliada ('.$bill->status.')';
        }

        if ($type === 'subscription_canceled' && $subExternal) {
            $subscription = $this->subscriptionFor((string) $subExternal);
            if (! $subscription) {
                return 'ignorado: assinatura desconhecida';
            }
            // Alteração feita diretamente no painel do provedor: registrar como evento externo e encaminhar revisão.
            $this->recorder->event($subscription, 'external_cancellation', null, null,
                'Cancelamento recebido do provedor; interpretar conforme política de encerramento', ['webhook' => $inbox->id], 'provider');
            $this->outbox->enqueue('vindi', 'review_external_cancellation', $subscription, ['subscription_number' => $subscription->number], 'inbox-'.$inbox->id);

            return 'cancelamento externo registrado para revisão';
        }

        return 'sem ação para '.$type;
    }

    public function reconcileSubscription(Subscription $subscription): int
    {
        $external = ExternalMapping::externalId($this->gateway->name(), $subscription);
        if (! $external) {
            return 0;
        }
        $bills = $this->gateway->listBills($external);
        foreach ($bills as $bill) {
            $this->sync->upsert($subscription, $bill, $this->gateway->name());
        }
        $this->financial->recompute($subscription->customer, 'conciliação');
        $this->activation->tryActivate($subscription, 'Conciliação');

        return count($bills);
    }

    /** @param bool $pendingOnly true: primeiras cobranças pendentes (frequente); false: auditoria diária completa */
    public function reconcileAll(bool $pendingOnly): array
    {
        $ok = 0;
        $failed = [];
        Subscription::with('customer')->where('billing_provider', $this->gateway->name())
            ->where('billing_status', 'created')
            ->when($pendingOnly, fn ($q) => $q->where('status', SubscriptionStatus::AwaitingRegularization->value))
            ->when(! $pendingOnly, fn ($q) => $q->whereNotIn('status', [SubscriptionStatus::Ended->value]))
            ->chunkById(50, function ($subs) use (&$ok, &$failed) {
                foreach ($subs as $sub) {
                    try {
                        $this->reconcileSubscription($sub);
                        $ok++;
                    } catch (BillingException $e) {
                        $failed[] = $sub->number.': '.$e->getMessage();
                    }
                }
            });

        // Resultado incerto de criação: consultar e adotar sem criar de novo.
        Subscription::where('billing_status', 'uncertain')->each(function (Subscription $sub) {
            if ($found = $this->gateway->findSubscriptionByCode($sub->number)) {
                DB::transaction(function () use ($sub, $found) {
                    ExternalMapping::link($this->gateway->name(), $sub, $found['subscription_id']);
                    if ($found['bill']) {
                        $this->sync->upsert($sub, $found['bill'], $this->gateway->name(), true);
                    }
                    $sub->forceFill(['billing_status' => 'created'])->save();
                });
            }
        });

        return ['ok' => $ok, 'failed' => $failed];
    }

    protected function applyBill(Subscription $subscription, BillData $bill): void
    {
        $this->sync->upsert($subscription, $bill, $this->gateway->name());
        $this->financial->recompute($subscription->customer, 'webhook');
        $this->activation->tryActivate($subscription, 'Pagamento confirmado pelo provedor');
    }

    protected function subscriptionFor(?string $externalId): ?Subscription
    {
        if (! $externalId) {
            return null;
        }
        $localId = ExternalMapping::where('system', $this->gateway->name())->where('local_type', (new Subscription)->getMorphClass())
            ->where('external_id', $externalId)->value('local_id');

        return $localId ? Subscription::find($localId) : null;
    }
}
