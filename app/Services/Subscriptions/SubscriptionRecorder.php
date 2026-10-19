<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Pet;
use App\Models\Proposal;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Support\Numbers;
use Carbon\CarbonInterface;

class SubscriptionRecorder
{
    /** Rascunho → aguardando regularização após conclusão da proposta (seção 9). */
    public function createFromProposal(Proposal $proposal, Contract $contract, Customer $customer, Pet $pet): Subscription
    {
        $subscription = Subscription::create([
            'number' => Numbers::generate('ASS', 'subscriptions'),
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'contract_id' => $contract->id,
            'proposal_id' => $proposal->id,
            'plan_version_id' => $proposal->plan_version_id,
            'originating_franchise_id' => $proposal->originating_franchise_id,
            'status' => SubscriptionStatus::AwaitingRegularization,
            'desired_start_date' => $proposal->desired_start_date,
        ]);

        $this->event($subscription, 'created', null, SubscriptionStatus::AwaitingRegularization, 'Proposta '.$proposal->number.' confirmada');

        return $subscription;
    }

    /**
     * Guarda quando o fato ocorreu e quando o sistema tomou conhecimento (webhooks podem atrasar).
     *
     * @param  array<string, mixed>|null  $data
     */
    public function event(
        Subscription $subscription,
        string $event,
        ?SubscriptionStatus $from = null,
        ?SubscriptionStatus $to = null,
        ?string $reason = null,
        ?array $data = null,
        string $source = 'system',
        ?CarbonInterface $occurredAt = null,
    ): SubscriptionEvent {
        return SubscriptionEvent::create([
            'subscription_id' => $subscription->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'reason' => $reason,
            'data' => $data,
            'source' => $source,
            'user_id' => auth()->id(),
            'occurred_at' => $occurredAt ?? now(),
            'recorded_at' => now(),
        ]);
    }

    public function transition(Subscription $subscription, SubscriptionStatus $to, string $event, ?string $reason = null, ?array $data = null, string $source = 'system', ?CarbonInterface $occurredAt = null): ?SubscriptionEvent
    {
        $from = $subscription->status;
        if ($from === $to) {
            return null;
        }
        $subscription->status = $to;
        $subscription->save();

        return $this->event($subscription, $event, $from, $to, $reason, $data, $source, $occurredAt);
    }
}
