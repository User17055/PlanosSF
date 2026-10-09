<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'paid_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $p) {
            if ($p->isDirty(['amount_cents', 'paid_at', 'invoice_id', 'external_transaction_id', 'source'])) {
                throw new \LogicException('O valor original de um pagamento não pode ser alterado; registre um ajuste.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Pagamentos não podem ser excluídos.'));
    }
}
