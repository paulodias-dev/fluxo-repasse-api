<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::query()->updateOrCreate(
            ['key' => 'users.manage'],
            ['description' => 'Gerenciar usuários administrativos']
        );

        $role = Role::query()->updateOrCreate(
            ['slug' => 'platform_admin'],
            ['name' => 'Administrador técnico']
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::query()->updateOrCreate(
            ['email' => 'admin.repasse@fluxosistemas.com.br'],
            [
                'name' => 'Administrador do Repasse',
                'password' => 'Tki&rn3233',
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
