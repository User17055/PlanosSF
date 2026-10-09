<?php

namespace App\Models;

use App\Enums\FillMode;
use App\Enums\ProposalChannel;
use App\Enums\ProposalStatus;
use App\Enums\SignatureMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'channel' => ProposalChannel::class,
            'fill_mode' => FillMode::class,
            'signature_method' => SignatureMethod::class,
            'status' => ProposalStatus::class,
            'conditions_snapshot' => 'array',
            'form_data' => 'array',
            'desired_start_date' => 'date:Y-m-d',
            'expires_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'token_revoked_at' => 'datetime',
            'link_sent_at' => 'datetime',
            'link_first_opened_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function originatingFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'originating_franchise_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            ProposalStatus::Created, ProposalStatus::AwaitingFill, ProposalStatus::AwaitingAcceptance,
        ], true) && $this->expires_at->isFuture();
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            ProposalStatus::Completed, ProposalStatus::Expired, ProposalStatus::Revoked, ProposalStatus::Canceled,
        ], true);
    }

    public function hasBeenAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function linkIsUsable(): bool
    {
        return $this->token_hash !== null
            && $this->token_revoked_at === null
            && $this->token_expires_at?->isFuture();
    }
}
