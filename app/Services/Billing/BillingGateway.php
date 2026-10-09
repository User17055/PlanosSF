<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\Subscription;

/**
 * Adaptador do provedor de cobrança recorrente: apenas traduz a API financeira para objetos internos.
 */
interface BillingGateway
{
    public function name(): string;

    /** Cria ou localiza o cliente de cobrança pelo código interno, sem duplicidade. */
    public function ensureCustomer(Customer $customer): string;

    /** @return array{subscription_id: string, bill: ?BillData} */
    public function createSubscription(Subscription $subscription, string $customerExternalId): array;

    /**
     * Consulta segura para resultado incerto (timeout depois do envio): procura pelo código interno.
     *
     * @return array{subscription_id: string, bill: ?BillData}|null
     */
    public function findSubscriptionByCode(string $code): ?array;

    public function getBill(string $billId): BillData;

    /** @return array<int, BillData> */
    public function listBills(string $subscriptionExternalId): array;

    public function cancelSubscription(string $subscriptionExternalId): void;
}
