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

        $accessPermission = Permission::query()->updateOrCreate(
            ['key' => 'roles.manage'],
            ['description' => 'Gerenciar papéis e atribuições de acesso']
        );

        $casePermission = Permission::query()->updateOrCreate(
            ['key' => 'cases.manage'],
            ['description' => 'Gerenciar dossiês documentais de repasse, sem movimentação financeira']
        );

        $scenarioPermission = Permission::query()->updateOrCreate(
            ['key' => 'scenarios.manage'],
            ['description' => 'Planejamento e comparação de cenários, sem executar dinheiro']
        );

        $role = Role::query()->updateOrCreate(
            ['slug' => 'platform_admin'],
            ['name' => 'Administrador técnico']
        );

        $role->permissions()->syncWithoutDetaching([$permission->id, $partyPermission->id, $accessPermission->id, $casePermission->id, $scenarioPermission->id]);

        // Provisionar usuários via Artisan: repasse:user:create e
        // repasse:access:bootstrap com confirmação, sem credenciais versionadas.
    }
}
