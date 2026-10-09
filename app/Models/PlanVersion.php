<?php

namespace App\Models;

use App\Enums\PlanVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanVersion extends Model
{
    protected $guarded = ['id'];

    /** Campos que ainda podem mudar depois da publicação (apenas controle de oferta). */
    public const MUTABLE_AFTER_PUBLISH = [
        'status', 'offer_ends_at', 'unpublished_at', 'unpublished_by', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanVersionStatus::class,
            'eligible_species' => 'array',
            'eligible_franchise_ids' => 'array',
            'offer_starts_at' => 'datetime',
            'offer_ends_at' => 'datetime',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'promo_valid_until' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function benefitRules(): HasMany
    {
        return $this->hasMany(BenefitRule::class)->orderBy('id');
    }

    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    public function isDraft(): bool
    {
        return $this->status === PlanVersionStatus::Draft;
    }

    public function wasPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Versões que podem ser oferecidas a novos clientes neste momento. */
    public function scopeOfferable(Builder $q): Builder
    {
        $now = now();

        return $q->where('status', PlanVersionStatus::Published->value)
            ->where(fn ($q) => $q->whereNull('offer_starts_at')->orWhere('offer_starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('offer_ends_at')->orWhere('offer_ends_at', '>', $now));
    }

    public function isOfferable(): bool
    {
        return $this->status === PlanVersionStatus::Published
            && ($this->offer_starts_at === null || $this->offer_starts_at->lte(now()))
            && ($this->offer_ends_at === null || $this->offer_ends_at->gt(now()));
    }

    public function availableForFranchise(int $franchiseId): bool
    {
        return empty($this->eligible_franchise_ids) || in_array($franchiseId, $this->eligible_franchise_ids, true);
    }

    public function effectivePriceCents(): int
    {
        $promoActive = $this->promo_price_cents !== null
            && ($this->promo_valid_until === null || $this->promo_valid_until->gt(now()));

        return $promoActive ? $this->promo_price_cents : $this->price_cents;
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if ($v->getOriginal('published_at') === null) {
                return;
            }
            $changed = array_diff(array_keys($v->getDirty()), self::MUTABLE_AFTER_PUBLISH);
            if ($changed !== []) {
                throw new \LogicException('Versão publicada é imutável. Crie uma nova versão do plano.');
            }
        });
        static::deleting(function (self $v) {
            if ($v->getOriginal('published_at') !== null) {
                throw new \LogicException('Versão publicada não pode ser excluída; use a despublicação.');
            }
        });
    }
}
