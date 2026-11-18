<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'financial.manage' => 'Preparar planos e declarar pagamentos externos',
            'financial.approve' => 'Confirmar obrigações internas e cancelamentos',
        ] as $key => $description) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['description' => $description, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        // Sem habilitar pagamentos bancários. Apenas proprietários/admin
        // da plataforma recebem o controle manual; outros perfis são
        // administrados pelas permissões explícitas do SaaS.
        $grants = DB::table('permissions')->whereIn('key', [
            'financial.manage', 'financial.approve',
        ])->pluck('id');
        $roles = DB::table('roles')->where('slug', 'platform_admin')
            ->orWhere('slug', 'like', 'tenant_owner_%')->pluck('id');
        foreach ($roles as $roleId) {
            foreach ($grants as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId], []
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', [
            'financial.manage', 'financial.approve',
        ])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
