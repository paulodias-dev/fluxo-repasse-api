<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetUserStatusRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use App\Services\AuthEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminUserController extends Controller
{
    public function __construct(private readonly AuthEventRecorder $events) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = User::query()->select(['id', 'name', 'email', 'is_active', 'created_at']);

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filters['search']));

            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%');
            });
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $users = $query->orderByDesc('id')->paginate(15)
            ->through(fn (User $user): array => $this->publicUser($user));

        return response()->json($users)->header('Cache-Control', 'no-store');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $roleIds = $data['role_ids'] ?? [];
        $assigningRoles = array_key_exists('role_ids', $data);
        $actor = $request->user('api');

        if ($assigningRoles && ! $actor->hasPermission('roles.manage')) {
            return response()->json(['message' => 'Sem permissão para definir os papéis do usuário.'], 403)
                ->header('Cache-Control', 'no-store');
        }

        $user = DB::transaction(function () use ($request, $data, $roleIds, $assigningRoles, $actor): User {
            if ($assigningRoles) {
                $roles = Role::query()->where('tenant_id', TenantContext::requireId())
                    ->whereIn('id', $roleIds)->with('permissions')->get();
                $allowed = $actor->grantedPermissions();

                if ($roles->count() !== count($roleIds) ||
                    $roles->contains(fn (Role $role) => $role->slug === 'platform_admin' ||
                        $role->permissions->pluck('key')->diff($allowed)->isNotEmpty())) {
                    throw ValidationException::withMessages([
                        'role_ids' => ['Não é permitido atribuir um ou mais dos papéis selecionados.'],
                    ]);
                }
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            if ($assigningRoles && count($roleIds) > 0) {
                $user->roles()->sync($roleIds);
            }

            $this->events->record('account_created', $request, $user, $actor);
            if ($assigningRoles && count($roleIds) > 0) {
                $this->events->record('account_roles_changed', $request, $user, $actor);
            }

            return $user;
        });

        return response()->json(['data' => $this->publicUser($user)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $changes = $request->validated();
        $assigningRoles = array_key_exists('role_ids', $changes);
        $roleIds = $changes['role_ids'] ?? [];
        unset($changes['role_ids']);
        $actor = $request->user('api');

        if ($assigningRoles && (! $actor->hasPermission('roles.manage') || $actor->id === $user->id)) {
            return response()->json(['message' => 'Não é permitido modificar os papéis desta conta.'], 403)
                ->header('Cache-Control', 'no-store');
        }

        DB::transaction(function () use ($request, $user, $changes, $assigningRoles, $roleIds, $actor): void {
            $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $roleChanged = false;

            if ($assigningRoles) {
                $allowed = $actor->grantedPermissions();

                if ($target->roles()->where('slug', 'platform_admin')->exists() ||
                    $target->roles()->whereHas('permissions', fn ($query) => $query->whereNotIn('key', $allowed))->exists()) {
                    abort(403, 'Papéis protegidos não podem ser substituídos.');
                }

                $roles = Role::query()->where('tenant_id', TenantContext::requireId())
                    ->whereIn('id', $roleIds)->with('permissions')->get();
                if ($roles->count() !== count($roleIds) ||
                    $roles->contains(fn (Role $role) => $role->slug === 'platform_admin' ||
                        $role->permissions->pluck('key')->diff($allowed)->isNotEmpty())) {
                    throw ValidationException::withMessages([
                        'role_ids' => ['Não é permitido atribuir um ou mais dos papéis selecionados.'],
                    ]);
                }

                $currentRoleIds = $target->roles()->pluck('roles.id')->all();
                $nextRoleIds = $roles->pluck('id')->all();
                sort($currentRoleIds);
                sort($nextRoleIds);
                $roleChanged = $currentRoleIds !== $nextRoleIds;
            }

            $target->fill($changes);
            $profileChanged = $target->isDirty();
            $emailChanged = $target->isDirty('email');
            if (! $profileChanged && ! $roleChanged) {
                return;
            }

            if ($emailChanged || $roleChanged) {
                // Uma unica revogacao JWT para edicoes simultaneas de email e papeis.
                $target->auth_version = (int) $target->auth_version + 1;
            }

            $target->save();
            if ($roleChanged) {
                $target->roles()->sync($roleIds);
                $this->events->record('account_roles_changed', $request, $target, $actor);
            }

            if ($profileChanged) {
                $this->events->record(
                    $emailChanged ? 'account_email_changed' : 'account_updated',
                    $request,
                    $target,
                    $actor,
                );
            }
        });

        return response()->json(['data' => $this->publicUser($user->fresh())])
            ->header('Cache-Control', 'no-store');
    }

    public function status(SetUserStatusRequest $request, User $user): JsonResponse
    {
        $active = (bool) $request->validated('is_active');

        if ($user->getKey() === $request->user('api')?->getKey()) {
            return response()->json(['message' => 'Não é possível alterar o status da própria conta.'], 403);
        }

        if (! $active && $user->roles()->where('slug', 'platform_admin')->exists()) {
            return response()->json([
                'message' => 'Contas administradoras devem ser desativadas pelo console.',
            ], 422);
        }

        $changed = DB::transaction(function () use ($request, $user, $active): bool {
            $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($target->is_active === $active) {
                return false;
            }

            // Revalidar a proteção de contas privilegiadas após obter lock do registro.
            if (! $active && $target->roles()->where('slug', 'platform_admin')->exists()) {
                throw ValidationException::withMessages([
                    'is_active' => ['Contas administradoras devem ser desativadas pelo console.'],
                ]);
            }

            $target->is_active = $active;
            $target->auth_version = (int) $target->auth_version + 1;
            $target->save();

            $this->events->record(
                $active ? 'account_activated' : 'account_deactivated',
                $request,
                $target,
                $request->user('api'),
            );

            return true;
        });

        return response()->json(['data' => $this->publicUser($user->fresh()), 'changed' => $changed])
            ->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
        ];
    }
}
