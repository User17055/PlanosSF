<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'is_initial' => 'boolean',
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'canceled_at' => 'datetime',
            'provider_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_at');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(FinancialAdjustment::class);
    }

    public function outstandingCents(): int
    {
        return max(0, $this->amount_cents - $this->paid_cents);
    }

    /**
     * Dívida vencida: saldo exigível depois do fim do dia de vencimento em America/Sao_Paulo (seção 27.3).
     */
    public function isOverdue(): bool
    {
        if (! in_array($this->status, [InvoiceStatus::Pending, InvoiceStatus::PartiallyPaid], true)) {
            return false;
        }

        return $this->outstandingCents() > 0
            && BusinessTime::isPastDueDate($this->due_date->format('Y-m-d'));
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [InvoiceStatus::Pending->value, InvoiceStatus::PartiallyPaid->value]);
    }

    /** Faturas com vencimento ultrapassado (o dia de vencimento já terminou no fuso do negócio). */
    public function scopeOverdue(Builder $q): Builder
    {
        return $q->open()->whereColumn('paid_cents', '<', 'amount_cents')
            ->where('due_date', '<', BusinessTime::today()->format('Y-m-d'));
    }
}
