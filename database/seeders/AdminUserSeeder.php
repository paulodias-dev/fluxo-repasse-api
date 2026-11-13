<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Prepara o RBAC sem criar contas ou senhas administrativas fixas.
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'users.manage'],
            ['description' => 'Gerenciar usuários administrativos']
        );

        $partyPermission = Permission::query()->updateOrCreate(
            ['key' => 'parties.manage'],
            ['description' => 'Gerenciar cadastro de pessoas e empresas']
        );

        $role = Role::query()->updateOrCreate(
            ['slug' => 'platform_admin'],
            ['name' => 'Administrador técnico']
        );

        $role->permissions()->syncWithoutDetaching([$permission->id, $partyPermission->id]);

        // Provisionar usuários via Artisan: repasse:user:create e
        // repasse:access:bootstrap com confirmação, sem credenciais versionadas.
    }
}
