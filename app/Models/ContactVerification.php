<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactVerification extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash', 'destination_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
