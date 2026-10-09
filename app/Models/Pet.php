<?php

namespace App\Models;

use App\Enums\Species;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'species' => Species::class,
            'birth_date' => 'date:Y-m-d',
            'estimated_age_reference_date' => 'date:Y-m-d',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Idade em meses na data civil informada (datas de nascimento não recebem conversão de fuso). */
    public function ageInMonths(?CarbonImmutable $on = null): ?int
    {
        $on ??= CarbonImmutable::now(config('planos.business_timezone'))->startOfDay();

        if ($this->birth_date) {
            return (int) CarbonImmutable::parse($this->birth_date->format('Y-m-d'))->diffInMonths($on);
        }

        if ($this->estimated_age_months !== null) {
            $ref = $this->estimated_age_reference_date
                ? CarbonImmutable::parse($this->estimated_age_reference_date->format('Y-m-d'))
                : CarbonImmutable::parse($this->created_at)->startOfDay();

            return $this->estimated_age_months + (int) $ref->diffInMonths($on);
        }

        return null;
    }
}
