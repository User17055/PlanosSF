<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcceptanceEvidence extends Model
{
    protected $table = 'acceptance_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Evidências de aceite são imutáveis.'));
        static::deleting(fn () => throw new \LogicException('Evidências de aceite não podem ser excluídas.'));
    }
}
