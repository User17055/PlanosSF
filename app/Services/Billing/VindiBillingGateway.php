<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Conector da API de recorrência da Vindi (https://vindi.github.io/api-docs/dist/).
 * ATENÇÃO (seção 12.1): validar produto contratado, métodos de pagamento, campos e eventos
 * no sandbox antes de habilitar em produção (PLANOS_BILLING_DRIVER=vindi).
 */
class VindiBillingGateway implements BillingGateway
{
    public function name(): string
    {
        return 'vindi';
    }

    public function ensureCustomer(Customer $customer): string
    {
        $code = 'SF-CUS-'.$customer->id;
        $found = $this->get('customers', ['query' => 'code:'.$code])->json('customers.0.id');
        if ($found) {
            return (string) $found;
        }

        $phone = preg_replace('/\D/', '', (string) $customer->phone);
        $response = $this->send('post', 'customers', array_filter([
            'name' => $customer->name,
            'email' => $customer->email,
            'registry_code' => $customer->document_normalized,
            'code' => $code,
            'address' => [
                'street' => $customer->street,
                'number' => $customer->number,
                'additional_details' => $customer->complement,
                'zipcode' => preg_replace('/\D/', '', (string) $customer->zip_code),
                'neighborhood' => $customer->district,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => 'BR',
            ],
            'phones' => $phone ? [['phone_type' => 'mobile', 'number' => '55'.$phone]] : null,
        ]));

        return (string) $response->json('customer.id');
    }

    public function createSubscription(Subscription $subscription, string $customerExternalId): array
    {
        $subscription->loadMissing('proposal');
        $cfg = config('planos.billing.vindi');
        $items = [[
            'product_id' => (int) $cfg['product_id'],
            'pricing_schema' => ['price' => $this->decimal($subscription->proposal->price_cents)],
        ]];
        if ($subscription->proposal->enrollment_fee_cents > 0 && $cfg['enrollment_product_id']) {
            $items[] = [
                'product_id' => (int) $cfg['enrollment_product_id'],
                'cycles' => 1,
                'pricing_schema' => ['price' => $this->decimal($subscription->proposal->enrollment_fee_cents)],
            ];
        }

        $response = $this->send('post', 'subscriptions', [
            'plan_id' => (int) $cfg['plan_id'],
            'customer_id' => (int) $customerExternalId,
            'payment_method_code' => $cfg['payment_method_code'],
            'code' => $subscription->number,
            'product_items' => $items,
        ], uncertainOnTimeout: true);

        $bill = $response->json('bill');

        return [
            'subscription_id' => (string) $response->json('subscription.id'),
            'bill' => $bill ? $this->toBillData($bill) : null,
        ];
    }

    public function findSubscriptionByCode(string $code): ?array
    {
        $sub = $this->get('subscriptions', ['query' => 'code:'.$code])->json('subscriptions.0');
        if (! $sub) {
            return null;
        }
        $bills = $this->listBills((string) $sub['id']);

        return ['subscription_id' => (string) $sub['id'], 'bill' => $bills[0] ?? null];
    }

    public function getBill(string $billId): BillData
    {
        return $this->toBillData($this->get('bills/'.$billId)->json('bill'));
    }

    public function listBills(string $subscriptionExternalId): array
    {
        $bills = $this->get('bills', [
            'query' => 'subscription_id:'.$subscriptionExternalId,
            'sort_by' => 'created_at', 'sort_order' => 'asc', 'per_page' => 50,
        ])->json('bills', []);

        return array_map(fn ($b) => $this->toBillData($b), $bills);
    }

    public function cancelSubscription(string $subscriptionExternalId): void
    {
        $this->send('delete', 'subscriptions/'.$subscriptionExternalId, ['cancel_bills' => false]);
    }

    /** @param array<string, mixed> $bill */
    protected function toBillData(array $bill): BillData
    {
        $provider = (string) ($bill['status'] ?? 'pending');
        $charges = array_map(fn ($c) => [
            'id' => (string) $c['id'],
            'status' => match ($c['status'] ?? '') {
                'paid' => 'paid', 'refunded' => 'refunded', 'chargeback' => 'disputed',
                'canceled', 'rejected', 'fraud_review' => $c['status'], default => 'pending',
            },
            'provider_status' => (string) ($c['status'] ?? ''),
            'amount_cents' => (int) Money::parse((string) ($c['amount'] ?? '0')),
            'paid_at' => ! empty($c['paid_at']) ? CarbonImmutable::parse($c['paid_at']) : null,
            'method' => $c['payment_method']['code'] ?? null,
        ], $bill['charges'] ?? []);

        $hasRefund = collect($charges)->contains(fn ($c) => $c['status'] === 'refunded');
        $hasDispute = collect($charges)->contains(fn ($c) => $c['status'] === 'disputed');

        return new BillData(
            externalId: (string) $bill['id'],
            subscriptionExternalId: isset($bill['subscription']['id']) ? (string) $bill['subscription']['id'] : null,
            status: match (true) {
                $hasDispute => 'disputed',
                $hasRefund => 'refunded',
                $provider === 'paid' => 'paid',
                $provider === 'canceled' => 'canceled',
                default => 'pending',
            },
            providerStatus: $provider,
            amountCents: (int) Money::parse((string) ($bill['amount'] ?? '0')),
            dueDate: CarbonImmutable::parse($bill['due_at'] ?? $bill['created_at'])->setTimezone(config('planos.business_timezone'))->format('Y-m-d'),
            periodStart: isset($bill['period']['start_at']) ? CarbonImmutable::parse($bill['period']['start_at'])->setTimezone(config('planos.business_timezone'))->format('Y-m-d') : null,
            periodEnd: isset($bill['period']['end_at']) ? CarbonImmutable::parse($bill['period']['end_at'])->setTimezone(config('planos.business_timezone'))->format('Y-m-d') : null,
            cycle: $bill['period']['cycle'] ?? null,
            paymentUrl: $bill['url'] ?? null,
            updatedAt: isset($bill['updated_at']) ? CarbonImmutable::parse($bill['updated_at']) : null,
            charges: $charges,
        );
    }

    protected function client(): PendingRequest
    {
        $cfg = config('planos.billing.vindi');
        if (blank($cfg['api_key'])) {
            throw new BillingException('Credencial da Vindi não configurada.', BillingException::KIND_AUTH);
        }

        return Http::baseUrl(rtrim($cfg['base_url'], '/'))
            ->withBasicAuth($cfg['api_key'], '')
            ->acceptJson()
            ->timeout($cfg['timeout'])
            ->connectTimeout(10);
    }

    /** @param array<string, mixed> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->send('get', $path, $query);
    }

    /** @param array<string, mixed> $data */
    protected function send(string $method, string $path, array $data = [], bool $uncertainOnTimeout = false): Response
    {
        try {
            $response = $this->client()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            // Timeout depois do envio: o provedor pode ter concluído a operação (seção 12 — resultado incerto).
            throw new BillingException('Sem resposta da Vindi.', $uncertainOnTimeout ? BillingException::KIND_UNCERTAIN : BillingException::KIND_TEMPORARY, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $kind = match (true) {
            in_array($response->status(), [401, 403], true) => BillingException::KIND_AUTH,
            $response->status() === 422, $response->status() === 404 => BillingException::KIND_VALIDATION,
            default => BillingException::KIND_TEMPORARY,
        };
        $errors = collect($response->json('errors', []))->pluck('message')->implode('; ');

        throw new BillingException('Vindi '.$response->status().($errors ? ': '.$errors : ''), $kind);
    }

    protected function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
