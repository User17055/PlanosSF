<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerFinancialEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['invoice_ids' => 'array', 'occurred_at' => 'datetime'];
    }
}
