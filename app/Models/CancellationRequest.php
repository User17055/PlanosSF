<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancellationRequest extends Model
{
    public const TYPE_IMMEDIATE = 'immediate';

    public const TYPE_END_OF_PERIOD = 'end_of_period';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'last_covered_period_end' => 'date:Y-m-d',
            'executed_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
