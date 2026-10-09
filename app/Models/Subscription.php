<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'desired_start_date' => 'date:Y-m-d',
            'activated_at' => 'datetime',
            'effective_start_at' => 'datetime',
            'end_at' => 'datetime',
            'canceled_at' => 'datetime',
            'billing_requested_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function originatingFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('due_date');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class)->latest();
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class)->latest();
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            || $this->status === SubscriptionStatus::CancellationScheduled;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [SubscriptionStatus::Canceled, SubscriptionStatus::Ended], true);
    }

    protected static function booted(): void
    {
        static::updating(function (self $s) {
            if ($s->getOriginal('activated_at') && $s->isDirty('activated_at')) {
                throw new \LogicException('activated_at registra a primeira ativação e não pode ser regravado.');
            }
            if ($s->getOriginal('effective_start_at') && $s->isDirty('effective_start_at')) {
                throw new \LogicException('effective_start_at não pode ser regravado.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Assinaturas não podem ser excluídas.'));
    }
}
