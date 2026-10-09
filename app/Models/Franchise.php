<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Franchise extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_central' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role_id', 'is_active'])->withTimestamps();
    }

    public static function central(): self
    {
        return static::where('is_central', true)->firstOrFail();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
