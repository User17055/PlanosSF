<?php

namespace App\Models;

use App\Enums\IntegrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationJob extends Model
{
    public const MODE_AUTOMATIC = 'automatic';

    public const MODE_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => IntegrationStatus::class,
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function destinationLabel(): string
    {
        return match ($this->destination) {
            'vindi' => 'Vindi',
            'simplesvet' => 'SimplesVet',
            'tudoprapet' => 'TudoPraPet',
            default => $this->destination,
        };
    }

    public function operationLabel(): string
    {
        return __('integrations.operations.'.$this->operation);
    }
}
