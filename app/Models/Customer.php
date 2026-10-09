<?php

namespace App\Models;

use App\Support\Mask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'marketing_opt_in' => 'boolean',
            'marketing_opt_in_at' => 'datetime',
        ];
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CustomerAttachment::class);
    }

    public function financialStatus(): HasOne
    {
        return $this->hasOne(CustomerFinancialStatus::class);
    }

    public function financialEvents(): HasMany
    {
        return $this->hasMany(CustomerFinancialEvent::class)->latest('occurred_at');
    }

    public function createdFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'created_franchise_id');
    }

    public function maskedDocument(): string
    {
        return Mask::document($this->document_normalized);
    }

    public function formattedDocument(): ?string
    {
        $d = $this->document_normalized;

        return $d && strlen($d) === 11
            ? substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9, 2)
            : $d;
    }

    /** Cadastro completo para contratação e cobrança (seção 5). */
    public function isComplete(): bool
    {
        return filled($this->name) && filled($this->document_normalized) && filled($this->email)
            && filled($this->phone) && filled($this->zip_code) && filled($this->street)
            && filled($this->number) && filled($this->city) && filled($this->state);
    }

    /** @return array<int, string> */
    public function missingFields(): array
    {
        $labels = [
            'name' => 'nome', 'document_normalized' => 'CPF', 'email' => 'e-mail', 'phone' => 'telefone',
            'zip_code' => 'CEP', 'street' => 'logradouro', 'number' => 'número', 'city' => 'cidade', 'state' => 'UF',
        ];

        return array_values(array_filter($labels, fn ($label, $field) => blank($this->{$field}), ARRAY_FILTER_USE_BOTH));
    }
}
