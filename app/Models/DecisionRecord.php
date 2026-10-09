<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DecisionRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_on' => 'date'];
    }
}
