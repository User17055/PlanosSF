<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitLedgerEntry extends Model
{
    public const TYPE_CONSUME = 'consume';

    public const TYPE_REVERSAL = 'reversal';

    protected $table = 'benefit_ledger';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(Authorization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_entry_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Lançamentos de benefício são imutáveis; use estorno.'));
        static::deleting(fn () => throw new \LogicException('Lançamentos de benefício não podem ser excluídos.'));
    }
}
