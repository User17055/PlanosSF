<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitPeriod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function benefitRule(): BelongsTo
    {
        return $this->belongsTo(BenefitRule::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(BenefitLedgerEntry::class);
    }

    public function authorizations(): HasMany
    {
        return $this->hasMany(Authorization::class);
    }
}
