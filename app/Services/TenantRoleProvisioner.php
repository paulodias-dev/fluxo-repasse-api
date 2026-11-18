<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Support\OperationalAccessCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Cargos fixos por empresa, idempotentes: Administrador e Usuário.
 * A mesma pessoa nunca recebe permissão acima da organização por herança.
 */
final class TenantRoleProvisioner
{
    /** @return array{administrator: Role, user: Role} */
    public function sync(int $tenantId): array
    {
        return DB::transaction(function () use ($tenantId): array {
            $admin = $this->upsertRole($tenantId, 'administrator', 'Administrador',
                OperationalAccessCatalog::keys());
            $user = $this->upsertRole($tenantId, 'user', 'Usuário', [
                'parties.manage', 'cases.manage', 'scenarios.manage',
                'packages.manage',
            ]);

            return ['administrator' => $admin, 'user' => $user];
        });
    }

    private function upsertRole(int $tenantId, string $slug, string $name, array $permissions): Role
    {
        $role = Role::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $tenantId, 'slug' => $slug],
            ['name' => $name]
        );
        $ids = Permission::query()->whereIn('key', $permissions)->pluck('id')->all();
        if (count($ids) !== count($permissions)) {
            throw new \RuntimeException('Catálogo de permissões incompleto. Execute AdminUserSeeder.');
        }
        $existing = $role->permissions()->pluck('permissions.id')->all();
        $desired = array_values($ids);
        sort($existing);
        sort($desired);
        if ($existing !== $desired) {
            $role->permissions()->sync($ids);
            // Permissões modificadas revogam sessões ativas dos membros.
            \App\Models\User::query()->where('tenant_id', $tenantId)
                ->whereHas('roles', fn ($q) => $q->whereKey($role->id))
                ->increment('auth_version');
        }

        return $role;
    }
}
