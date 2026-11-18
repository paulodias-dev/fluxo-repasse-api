<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthEventRecorder;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccessManagementController extends Controller
{
    public function __construct(private readonly AuthEventRecorder $events) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user('api');
        $keys = $actor->grantedPermissions();
        $roles = Role::query()->with('permissions:id,key')
            ->withCount(['users' => fn ($query) => $query->where('users.tenant_id', app(TenantContext::class)->id())])
            ->whereIn('slug', ['tenant_admin', 'tenant_user'])->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id, 'slug' => $role->slug, 'name' => $role->name,
                'permissions' => $role->permissions->pluck('key')->values(),
                'users_count' => $role->users_count,
                'editable' => false,
            ]);
        return response()->json([
            'roles' => $roles,
            'permissions' => Permission::query()->whereIn('key', $keys)->orderBy('key')
                ->get(['key', 'description']),
        ])->header('Cache-Control', 'no-store');
    }

    public function saveRole(Request $request, ?Role $role = null): JsonResponse
    {
        // Cargos globais e imutáveis: alteração de template compartilhado
        // por um tenant afetaria todas as empresas. Concessões individuais
        // permanecem disponíveis em assign().
        return response()->json([
            'message' => 'Cargos funcionais são gerenciados pela plataforma.',
        ], 403)->header('Cache-Control', 'no-store');
    }

    public function userRoles(Request $request, User $user): JsonResponse
    {
        if ($user->roles()->where('slug', 'platform_admin')->exists()) {
            return response()->json(['message' => 'Conta técnica protegida.'], 403);
        }
        return response()->json([
            'user_id' => $user->id,
            'roles' => $user->roles()->where('slug', '!=', 'platform_admin')
                ->get(['roles.id', 'roles.slug', 'roles.name']),
        ])->header('Cache-Control', 'no-store');
    }

    public function assign(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')],
        ]);
        $actor = $request->user('api');
        if ($user->id === $actor->id) {
            return response()->json(['message' => 'Não é permitido alterar os próprios acessos.'], 403);
        }
        $allowed = $actor->grantedPermissions();
        DB::transaction(function () use ($user, $actor, $request, $data, $allowed): void {
            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($target->roles()->where('slug', 'platform_admin')->exists()) abort(403);
            $selected = Role::query()->whereIn('id', $data['role_ids'])
                ->whereIn('slug', ['tenant_admin', 'tenant_user'])->with('permissions')->get();
            if ($selected->count() !== count($data['role_ids']) ||
                $selected->contains(fn (Role $role) => $role->slug === 'platform_admin' ||
                    $role->permissions->pluck('key')->diff($allowed)->isNotEmpty())) {
                throw ValidationException::withMessages(['role_ids' => 'Um ou mais papéis não podem ser concedidos.']);
            }
            if ($target->roles()->whereHas('permissions', fn ($q) => $q->whereNotIn('key', $allowed))->exists()) {
                abort(403);
            }
            $currentIds = $target->roles()->pluck('roles.id')->all();
            $newIds = $selected->pluck('id')->all();
            sort($currentIds); sort($newIds);
            if ($currentIds === $newIds) return;
            $target->roles()->sync($newIds);
            $target->auth_version = (int) $target->auth_version + 1;
            $target->save();
            $this->events->record('account_roles_changed', $request, $target, $actor);
        });
        return $this->userRoles($request, $user);
    }
}
