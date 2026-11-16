<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthEventRecorder;
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
        $roles = Role::query()->with('permissions:id,key')->withCount('users')
            ->where('slug', '!=', 'platform_admin')->orderBy('name')->get()
            ->map(fn (Role $role) => [
                'id' => $role->id, 'slug' => $role->slug, 'name' => $role->name,
                'permissions' => $role->permissions->pluck('key')->values(),
                'users_count' => $role->users_count,
                'editable' => $role->permissions->pluck('key')->diff($keys)->isEmpty(),
            ]);
        return response()->json([
            'roles' => $roles,
            'permissions' => Permission::query()->whereIn('key', $keys)->orderBy('key')
                ->get(['key', 'description']),
        ])->header('Cache-Control', 'no-store');
    }

    public function saveRole(Request $request, ?Role $role = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => [$role ? 'prohibited' : 'required', 'string', 'regex:/^[a-z][a-z0-9_]{2,79}$/',
                'max:80', Rule::unique('roles', 'slug')],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::exists('permissions', 'key')],
        ]);
        $actor = $request->user('api');
        $allowed = $actor->grantedPermissions();
        $requested = $data['permissions'];
        if (array_diff($requested, $allowed)) {
            throw ValidationException::withMessages(['permissions' => 'Não é permitido conceder acessos superiores aos seus.']);
        }
        if ($role && ($role->slug === 'platform_admin' ||
            $role->permissions()->whereNotIn('key', $allowed)->exists())) {
            return response()->json(['message' => 'Este papel é protegido.'], 403);
        }

        $role = DB::transaction(function () use ($role, $data, $actor, $request): Role {
            if ($role) {
                $target = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                if ($target->slug === 'platform_admin' ||
                    $target->permissions()->whereNotIn('key', $actor->grantedPermissions())->exists()) {
                    abort(403);
                }
                $target->name = trim($data['name']);
                $target->save();
            } else {
                $target = Role::query()->create(['slug' => $data['slug'], 'name' => trim($data['name'])]);
            }
            $permissionIds = Permission::query()->whereIn('key', $data['permissions'])->pluck('id')->all();
            $target->permissions()->sync($permissionIds);
            // Alterações de privilégios invalidam tokens dos usuários afetados.
            User::query()->whereHas('roles', fn ($q) => $q->whereKey($target->id))
                ->increment('auth_version');
            $this->events->record('access_role_saved', $request, $actor, $actor);
            return $target->load('permissions:id,key');
        });
        return response()->json([
            'data' => ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name,
                'permissions' => $role->permissions->pluck('key')->values()],
        ], 200)->header('Cache-Control', 'no-store');
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
            $selected = Role::query()->whereIn('id', $data['role_ids'])->with('permissions')->get();
            if ($selected->count() !== count($data['role_ids']) ||
                $selected->contains(fn (Role $role) => $role->slug === 'platform_admin' ||
                    $role->permissions->pluck('key')->diff($allowed)->isNotEmpty())) {
                throw ValidationException::withMessages(['role_ids' => 'Um ou mais papéis não podem ser concedidos.']);
            }
            if ($target->roles()->whereHas('permissions', fn ($q) => $q->whereNotIn('key', $allowed))->exists()) {
                return abort(403);
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
