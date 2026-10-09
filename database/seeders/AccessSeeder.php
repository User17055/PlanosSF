<?php

namespace Database\Seeders;

use App\Models\Franchise;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Access\PermissionCatalog;
use Illuminate\Database\Seeder;

/**
 * Permissões, perfis e a unidade de gestão central. Idempotente: pode rodar a cada implantação.
 */
class AccessSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::permissions() as $key => [$name, $group]) {
            Permission::updateOrCreate(['key' => $key], ['name' => $name, 'group' => $group]);
        }

        foreach (PermissionCatalog::roles() as $key => $def) {
            $role = Role::updateOrCreate(['key' => $key], ['name' => $def['name'], 'description' => $def['description']]);
            $role->permissions()->sync(Permission::whereIn('key', $def['permissions'])->pluck('id'));
        }

        Franchise::firstOrCreate(['is_central' => true], [
            'code' => 'CENTRAL',
            'name' => 'Gestão Central São Francisco',
            'status' => 'active',
        ]);
    }
}
