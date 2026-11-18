<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\OperationalAccessCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantController extends Controller
{
    /** Somente empresas vinculadas à própria conta; não revela outros tenants. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('api');

        return response()->json(['data' => DB::table('tenant_users as m')
            ->join('tenants as t', 't.id', '=', 'm.tenant_id')
            ->where('m.user_id', $user->id)
            ->where('m.is_active', true)
            ->where('t.is_active', true)
            ->select('t.id', 't.name', 't.slug', 'm.membership_role')
            ->orderBy('t.name')->get(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Provisionamento de empresas é exclusivo do administrador da plataforma,
     * não de um usuário comum com cases.manage. Sem atribuição automática a
     * outros usuários ou acesso a dados empresariais cruzados.
     */
    public function store(Request $request): JsonResponse
    {
        $unknown = array_diff(array_keys($request->all()), ['name']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Campo não permitido.'));
        }
        $name = trim($request->validate([
            'name' => ['required', 'string', 'min:3', 'max:180'],
        ])['name']);
        $user = $request->user('api');
        if (! $user->belongsToLegacyPlatformAdmin()) {
            abort(403, 'Apenas o administrador da plataforma pode criar empresas.');
        }

        $slug = Str::slug($name);
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'Nome inválido.']);
        }

        $id = DB::transaction(function () use ($name, $slug, $user): int {
            // Antes de habilitar a segunda empresa, preservar exatamente as
            // permissões herdadas da instalação single-org para o tenant 1.
            if (DB::table('tenants')->count() === 1) {
                $defaultTenant = DB::table('tenants')->value('id');
                $memberIds = DB::table('tenant_users')
                    ->where('tenant_id', $defaultTenant)->pluck('user_id');
                foreach (DB::table('role_user')->whereIn('user_id', $memberIds)->get() as $role) {
                    DB::table('tenant_role_user')->updateOrInsert([
                        'tenant_id' => $defaultTenant, 'user_id' => $role->user_id,
                        'role_id' => $role->role_id,
                    ], []);
                }
            }
            if (DB::table('tenants')->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['name' => 'Já existe empresa com esse nome.']);
            }
            $id = DB::table('tenants')->insertGetId([
                'name' => $name, 'slug' => $slug, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('tenant_users')->insert([
                'tenant_id' => $id, 'user_id' => $user->id,
                'membership_role' => 'owner', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $tenantRole = Role::withoutGlobalScopes()->firstOrCreate(
                ['slug' => 'tenant_owner_'.$id],
                ['name' => 'Administrador da empresa', 'tenant_id' => $id]
            );
            $permissions = DB::table('permissions')->whereIn(
                'key', array_keys(OperationalAccessCatalog::TECHNICAL_PERMISSIONS)
            )->pluck('id')->all();
            $tenantRole->permissions()->syncWithoutDetaching($permissions);
            DB::table('tenant_role_user')->insert([
                'tenant_id' => $id, 'user_id' => $user->id, 'role_id' => $tenantRole->id,
            ]);

            return $id;
        }, 3);

        return response()->json(['data' => [
            'id' => $id, 'name' => $name, 'slug' => $slug, 'membership_role' => 'owner',
        ]], 201)->header('Cache-Control', 'no-store, private');
    }
}
