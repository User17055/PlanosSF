<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Registros de auditoria não podem ser alterados.'));
        static::deleting(fn () => throw new \LogicException('Registros de auditoria não podem ser excluídos.'));
    }
}
