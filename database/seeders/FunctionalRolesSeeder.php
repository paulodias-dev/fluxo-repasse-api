<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\OperationalAccessCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FunctionalRolesSeeder extends Seeder
{
    /**
     * Global, immutable role templates. User accounts always belong to a
     * single tenant; role_user references the user (never another tenant).
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            // Technical permissions describe actual UI/API modules, not
            // authority to move money or approve obligations.
            $functional = [
                'tenant_admin' => [
                    'name' => 'Administrador',
                    'permissions' => OperationalAccessCatalog::keys(),
                ],
                'tenant_user' => [
                    'name' => 'Usuário',
                    'permissions' => [
                        'parties.manage', 'cases.manage', 'scenarios.manage',
                        'scenario_reviews.manage', 'packages.manage',
                    ],
                ],
            ];

            foreach ($functional as $slug => $config) {
                $role = Role::query()->updateOrCreate(
                    ['slug' => $slug], ['name' => $config['name']]
                );
                $ids = Permission::query()->whereIn('key', $config['permissions'])->pluck('id')->all();
                $old = $role->permissions()->pluck('permissions.id')->all();
                sort($old);
                $next = $ids;
                sort($next);

                if ($old !== $next) {
                    $role->permissions()->sync($ids);
                    // Role permissions are global: revoke all affected JWTs
                    // whenever the grants of a shared template change.
                    $role->users()->increment('auth_version');
                }
            }
        });
    }
}
