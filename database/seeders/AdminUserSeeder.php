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
                ['slug' => 'platform_admin', 'tenant_id' => null],
                ['name' => 'Administrador técnico']
            );

            $existing = $role->permissions()->pluck('permissions.id')->all();
            $missing = array_values(array_diff($ids, $existing));
            if ($missing === []) return;

            $role->permissions()->syncWithoutDetaching($missing);

            // Privileges have changed. All JWTs issued to existing members of
            // this role must be invalidated, including JWTs already in memory.
            User::query()->whereHas('roles', fn ($q) => $q->whereKey($role->id))
                ->increment('auth_version');
        });

        // This seeder never creates, identifies or automatically promotes a user.
        // Only explicitly bootstrapped accounts can receive platform_admin.
    }
}
