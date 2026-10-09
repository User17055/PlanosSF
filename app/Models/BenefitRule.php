<?php

namespace App\Models;

use App\Enums\CoverageType;
use App\Enums\PeriodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'coverage_type' => CoverageType::class,
            'period_type' => PeriodType::class,
            'discount_percent' => 'decimal:2',
            'requires_authorization' => 'boolean',
            'allowed_franchise_ids' => 'array',
            'cumulative' => 'boolean',
        ];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function isUnlimited(): bool
    {
        return $this->quantity_limit === null;
    }

    public function allowsFranchise(int $franchiseId): bool
    {
        return empty($this->allowed_franchise_ids) || in_array($franchiseId, $this->allowed_franchise_ids, true);
    }

    protected static function booted(): void
    {
        $guard = function (self $rule) {
            $versionId = $rule->getOriginal('plan_version_id') ?? $rule->plan_version_id;
            $version = PlanVersion::find($versionId);
            if ($version && $version->published_at !== null) {
                throw new \LogicException('Benefícios de versão publicada não podem ser alterados.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }
}
