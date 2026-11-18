<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Tenant;
use App\Support\TenantRoles;
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

            // Papéis Administrador/Usuário de cada empresa são provisionados
            // por TenantRoles::provision(), NÃO partilhados entre tenants.

        });

        // Sincroniza apenas os dois papéis funcionais padronizados de cada empresa;
        // papéis customizados mantêm suas concessões explícitas.
        // A reatribuição invalida os JWTs afetados pelo auth_version.
        Tenant::query()->pluck('id')->each(
            static fn ($tenantId) => TenantRoles::provision((int) $tenantId)
        );

        // This seeder never creates, identifies or automatically promotes a user.
        // Only explicitly bootstrapped accounts can receive platform_admin.
    }
}
