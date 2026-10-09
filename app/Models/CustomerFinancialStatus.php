<?php

namespace App\Models;

use App\Enums\FinancialState;
use Illuminate\Database\Eloquent\Model;

class CustomerFinancialStatus extends Model
{
    protected $table = 'customer_financial_status';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => FinancialState::class,
            'oldest_due_date' => 'date:Y-m-d',
            'computed_at' => 'datetime',
        ];
    }
}
