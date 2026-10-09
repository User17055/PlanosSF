<?php

namespace App\Models;

use App\Enums\PlanVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->orderByDesc('version');
    }

    public function publishedVersions(): HasMany
    {
        return $this->hasMany(PlanVersion::class)->where('status', PlanVersionStatus::Published->value);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $plan) {
            if ($plan->versions()->exists()) {
                throw new \LogicException('Plano com versões não pode ser excluído; use a inativação.');
            }
        });
    }
}
