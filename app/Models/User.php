<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, array<int, string>> cache por requisição: franchise_id => chaves de permissão */
    protected array $permissionCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FranchiseUser::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('is_active', true)
            ->whereHas('franchise', fn ($q) => $q->where('status', 'active'));
    }

    public function membershipFor(int $franchiseId): ?FranchiseUser
    {
        return $this->activeMemberships()->where('franchise_id', $franchiseId)
            ->with('role.permissions', 'franchise')->first();
    }

    /** @return array<int, string> */
    public function permissionsFor(int $franchiseId): array
    {
        if (! array_key_exists($franchiseId, $this->permissionCache)) {
            $membership = $this->membershipFor($franchiseId);
            $this->permissionCache[$franchiseId] = $membership
                ? $membership->role->permissions->pluck('key')->all()
                : [];
        }

        return $this->permissionCache[$franchiseId];
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = [];
    }
}
