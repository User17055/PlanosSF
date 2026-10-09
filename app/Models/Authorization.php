<?php

namespace App\Models;

use App\Enums\AuthorizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Authorization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AuthorizationStatus::class,
            'evaluation' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function benefitRule(): BelongsTo
    {
        return $this->belongsTo(BenefitRule::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BenefitPeriod::class, 'benefit_period_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function servicingFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'servicing_franchise_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(BenefitLedgerEntry::class);
    }

    public function isReservedAndValid(): bool
    {
        return $this->status === AuthorizationStatus::Reserved && $this->expires_at->isFuture();
    }
}
