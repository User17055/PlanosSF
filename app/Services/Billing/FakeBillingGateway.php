<?php

namespace App\Services\Billing;

use App\Models\Customer;
use App\Models\Subscription;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Simulador de cobrança para homologação. Segue o mesmo contrato do provedor real:
 * a página de pagamento é externa ao fluxo e a confirmação chega por webhook.
 */
class FakeBillingGateway implements BillingGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function ensureCustomer(Customer $customer): string
    {
        $code = 'cus-'.$customer->id;
        $existing = DB::table('fake_billing_records')->where('type', 'customer')->where('code', $code)->first();
        if ($existing) {
            return 'fc_'.$existing->id;
        }
        $id = DB::table('fake_billing_records')->insertGetId([
            'type' => 'customer', 'code' => $code, 'data' => json_encode(['name' => $customer->name]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'fc_'.$id;
    }

    public function createSubscription(Subscription $subscription, string $customerExternalId): array
    {
        if ($found = $this->findSubscriptionByCode($subscription->number)) {
            return $found;
        }
        $subscription->loadMissing('proposal');
        $subId = DB::table('fake_billing_records')->insertGetId([
            'type' => 'subscription', 'code' => $subscription->number, 'parent_id' => $customerExternalId,
            'data' => json_encode([
                'status' => 'active',
                'price_cents' => $subscription->proposal->price_cents,
                'interval' => (int) ($subscription->proposal->conditions_snapshot['billing_interval_months'] ?? 1),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $externalSub = 'fs_'.$subId;

        $bill = $this->createBill($externalSub, 1, $subscription->proposal->price_cents + $subscription->proposal->enrollment_fee_cents, BusinessTime::today());

        return ['subscription_id' => $externalSub, 'bill' => $bill];
    }

    public function findSubscriptionByCode(string $code): ?array
    {
        $row = DB::table('fake_billing_records')->where('type', 'subscription')->where('code', $code)->first();
        if (! $row) {
            return null;
        }
        $externalSub = 'fs_'.$row->id;
        $bills = $this->listBills($externalSub);

        return ['subscription_id' => $externalSub, 'bill' => $bills[0] ?? null];
    }

    public function getBill(string $billId): BillData
    {
        $row = DB::table('fake_billing_records')->where('type', 'bill')->where('id', $this->rawId($billId))->first();
        if (! $row) {
            throw new BillingException('Fatura não encontrada no simulador.', BillingException::KIND_VALIDATION);
        }

        return $this->toBillData($row);
    }

    public function listBills(string $subscriptionExternalId): array
    {
        return DB::table('fake_billing_records')->where('type', 'bill')->where('parent_id', $subscriptionExternalId)
            ->orderBy('id')->get()->map(fn ($row) => $this->toBillData($row))->all();
    }

    public function cancelSubscription(string $subscriptionExternalId): void
    {
        $row = DB::table('fake_billing_records')->where('type', 'subscription')->where('id', $this->rawId($subscriptionExternalId))->first();
        if ($row) {
            $data = json_decode($row->data, true);
            $data['status'] = 'canceled';
            DB::table('fake_billing_records')->where('id', $row->id)->update(['data' => json_encode($data), 'updated_at' => now()]);
        }
    }

    // ---- Ferramentas exclusivas do simulador de homologação ----

    public function createBill(string $subscriptionExternalId, int $cycle, int $amountCents, CarbonImmutable $periodStart): BillData
    {
        $sub = DB::table('fake_billing_records')->where('id', $this->rawId($subscriptionExternalId))->first();
        $interval = (int) (json_decode($sub->data, true)['interval'] ?? 1);
        $periodEnd = BusinessTime::addMonthsAnchored($periodStart, $interval)->subDay();
        $id = DB::table('fake_billing_records')->insertGetId([
            'type' => 'bill', 'code' => (string) Str::uuid(), 'parent_id' => $subscriptionExternalId,
            'data' => json_encode([
                'status' => 'pending', 'amount_cents' => $amountCents, 'cycle' => $cycle,
                'due_date' => $periodStart->format('Y-m-d'),
                'period_start' => $periodStart->format('Y-m-d'), 'period_end' => $periodEnd->format('Y-m-d'),
                'charges' => [], 'updated_at' => now()->toIso8601String(),
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->getBill('fb_'.$id);
    }

    public function createNextBill(string $subscriptionExternalId): BillData
    {
        $bills = $this->listBills($subscriptionExternalId);
        $last = end($bills);
        $sub = DB::table('fake_billing_records')->where('id', $this->rawId($subscriptionExternalId))->first();
        $data = json_decode($sub->data, true);
        $start = $last ? CarbonImmutable::parse($last->periodEnd, BusinessTime::tz())->addDay() : BusinessTime::today();

        return $this->createBill($subscriptionExternalId, ($last?->cycle ?? 0) + 1, (int) $data['price_cents'], $start);
    }

    /** @param array<string, mixed> $changes */
    public function updateBill(string $billId, array $changes): BillData
    {
        $row = DB::table('fake_billing_records')->where('id', $this->rawId($billId))->first();
        $data = array_merge(json_decode($row->data, true), $changes, ['updated_at' => now()->toIso8601String()]);
        DB::table('fake_billing_records')->where('id', $row->id)->update(['data' => json_encode($data), 'updated_at' => now()]);

        return $this->getBill($billId);
    }

    public function pay(string $billId, ?CarbonImmutable $paidAt = null): BillData
    {
        $bill = $this->getBill($billId);
        $charges = [[
            'id' => 'fch_'.Str::lower(Str::random(10)), 'status' => 'paid', 'amount_cents' => $bill->amountCents,
            'paid_at' => ($paidAt ?? CarbonImmutable::now())->toIso8601String(), 'method' => 'credit_card',
        ]];

        return $this->updateBill($billId, ['status' => 'paid', 'charges' => $charges]);
    }

    public function refund(string $billId): BillData
    {
        $row = DB::table('fake_billing_records')->where('id', $this->rawId($billId))->first();
        $charges = json_decode($row->data, true)['charges'] ?? [];
        foreach ($charges as &$charge) {
            $charge['status'] = 'refunded';
        }
        unset($charge);

        return $this->updateBill($billId, ['status' => 'refunded', 'charges' => $charges]);
    }

    protected function toBillData(object $row): BillData
    {
        $d = json_decode($row->data, true);
        $id = 'fb_'.$row->id;

        return new BillData(
            externalId: $id,
            subscriptionExternalId: $row->parent_id,
            status: match ($d['status']) {
                'paid' => 'paid', 'canceled' => 'canceled', 'refunded' => 'refunded', 'disputed' => 'disputed',
                default => 'pending',
            },
            providerStatus: $d['status'],
            amountCents: (int) $d['amount_cents'],
            dueDate: $d['due_date'],
            periodStart: $d['period_start'] ?? null,
            periodEnd: $d['period_end'] ?? null,
            cycle: $d['cycle'] ?? null,
            paymentUrl: URL::signedRoute('homolog.billing.show', ['bill' => $id]),
            updatedAt: isset($d['updated_at']) ? CarbonImmutable::parse($d['updated_at']) : null,
            charges: array_map(fn ($c) => [
                'id' => $c['id'],
                'status' => $c['status'],
                'provider_status' => $c['status'],
                'amount_cents' => (int) $c['amount_cents'],
                'paid_at' => ! empty($c['paid_at']) ? CarbonImmutable::parse($c['paid_at']) : null,
                'method' => $c['method'] ?? null,
            ], $d['charges'] ?? []),
        );
    }

    protected function rawId(string $externalId): int
    {
        return (int) preg_replace('/^\w+?_/', '', $externalId);
    }
}
