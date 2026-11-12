<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SetUserStatusRequest;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Models\User;
use App\Services\AuthEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

final class AdminUserController extends Controller
{
    public function __construct(private readonly AuthEventRecorder $events)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
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

        $users = $query->orderByDesc('id')->paginate(15);

        return response()->json($users)->header('Cache-Control', 'no-store');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::query()->create($request->validated());
            $this->events->record('account_created', $request, $user, $request->user('api'));

            return $user;
        });

        return response()->json(['data' => $this->publicUser($user)], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $changes = $request->validated();

        DB::transaction(function () use ($request, $user, $changes): void {
            $target = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($changes !== []) {
                $target->fill($changes);
                $target->save();
                $this->events->record('account_updated', $request, $target, $request->user('api'));
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
