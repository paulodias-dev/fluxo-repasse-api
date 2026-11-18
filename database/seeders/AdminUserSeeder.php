<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\OperationalAccessCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $ids = [];
            foreach (OperationalAccessCatalog::TECHNICAL_PERMISSIONS as $key => $description) {
                $permission = Permission::query()->updateOrCreate(
                    ['key' => $key], ['description' => $description]
                );
                $ids[] = $permission->id;
            }

            $role = Role::query()->updateOrCreate(
                ['slug' => 'platform_admin'], ['name' => 'Administrador técnico']
            );

            $existing = $role->permissions()->pluck('permissions.id')->all();
            $missing = array_values(array_diff($ids, $existing));
            if ($missing !== []) {
                $role->permissions()->syncWithoutDetaching($missing);
                User::query()->whereHas('roles', fn ($q) => $q->whereKey($role->id))
                    ->increment('auth_version');
            }

            // Papéis funcionais globais e imutáveis por empresa.
            // Não confundir o administrador de uma empresa com platform_admin.
            $functional = [
                'tenant_admin' => [
                    'name' => 'Administrador',
                    'keys' => array_keys(OperationalAccessCatalog::TECHNICAL_PERMISSIONS),
                ],
                'tenant_user' => [
                    'name' => 'Usuário',
                    'keys' => ['parties.manage', 'cases.manage', 'scenarios.manage'],
                ],
            ];
            foreach ($functional as $slug => $definition) {
                $functionalRole = Role::query()->updateOrCreate(
                    ['slug' => $slug], ['name' => $definition['name']]
                );
                $permissionIds = Permission::query()->whereIn('key', $definition['keys'])
                    ->pluck('id')->all();
                $current = $functionalRole->permissions()->pluck('permissions.id')->all();
                sort($current);
                $expected = $permissionIds;
                sort($expected);
                if ($current === $expected) continue;
                $functionalRole->permissions()->sync($permissionIds);
                User::query()->whereHas('roles', fn ($q) => $q->whereKey($functionalRole->id))
                    ->increment('auth_version');
            }


        });

        // This seeder never creates, identifies or automatically promotes a user.
        // Only explicitly bootstrapped accounts can receive platform_admin.
    }
}
