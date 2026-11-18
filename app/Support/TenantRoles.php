<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Papéis funcionais reais, exclusivos de uma empresa.
 * Cada empresa possui seu próprio Administrador e Usuário; compartilhar
 * a mesma matriz de permissões não significa compartilhar um papel editável.
 */
final class TenantRoles
{
    public static function provision(int $tenantId): array
    {
        return DB::transaction(function () use ($tenantId): array {
            $result = [];
            // O cadastro público também pode ser o primeiro fluxo executado
            // após as migrations. Garanta que o catálogo global exista antes
            // de montar os papéis isolados da empresa.
            foreach (OperationalAccessCatalog::TECHNICAL_PERMISSIONS as $key => $description) {
                Permission::query()->firstOrCreate(
                    ['key' => $key], ['description' => $description]
                );
            }
            foreach ([
                'tenant_admin' => [
                    'name' => 'Administrador',
                    'keys' => array_keys(OperationalAccessCatalog::TECHNICAL_PERMISSIONS),
                ],
                'tenant_user' => [
                    'name' => 'Usuário',
                    'keys' => ['parties.manage', 'cases.manage', 'scenarios.manage', 'repasse.manage'],
                ],
            ] as $slug => $definition) {
                $role = Role::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'slug' => $slug],
                    ['name' => $definition['name']]
                );
                $ids = Permission::query()->whereIn('key', $definition['keys'])
                    ->pluck('id')->all();
                $current = $role->permissions()->pluck('permissions.id')->all();
                sort($ids);
                sort($current);
                if ($ids !== $current) {
                    $role->permissions()->sync($ids);
                    User::query()->where('tenant_id', $tenantId)
                        ->whereHas('roles', fn ($query) => $query->whereKey($role->id))
                        ->increment('auth_version');
                }
                $result[$slug] = $role;
            }

            return $result;
        });
    }
}
