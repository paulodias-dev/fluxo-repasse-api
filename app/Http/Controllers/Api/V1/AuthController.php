<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Services\AuthEventRecorder;
use App\Support\OperationalAccessCatalog;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthEventRecorder $events) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        $token = Auth::guard('api')->attempt([...$credentials, 'is_active' => true]);

        if (! $token) {
            $this->events->record('login_failed', $request);

            return response()->json(['message' => 'Credenciais inválidas.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $user = Auth::guard('api')->user();
        if (! $user->tenant_id || ! DB::table('tenants')->where('id', $user->tenant_id)
            ->where('is_active', true)->exists()) {
            Auth::guard('api')->logout();
            return response()->json(['message' => 'Empresa indisponível.'], 403)
                ->header('Cache-Control', 'no-store');
        }
        $this->events->record('login_succeeded', $request, $user);

        return $this->tokenResponse($token);
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        return response()->json([
            'data' => [
                'id' => $user->getAuthIdentifier(),
                ...$this->profileData($user),
                'permissions' => $user->grantedPermissions(),
                'roles' => $user->roles()->pluck('slug')->values()->all(),
                'tenant' => DB::table('tenants')->where('id', $user->tenant_id)
                    ->first(['id', 'name', 'slug']),
                'access_diagnostic' => $this->diagnostic($user),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user('api');
        $data = $request->validated();
        $oldAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        if ($request->hasFile('avatar')) {
            $newAvatarPath = $request->file('avatar')->store('avatars/'.$user->getKey(), 'public');
        }

        try {
            [$fresh, $emailChanged] = DB::transaction(function () use ($user, $data, $newAvatarPath): array {
                $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $emailChanged = $current->email !== $data['email'];

                unset($data['avatar']);
                if ($newAvatarPath !== null) {
                    $data['avatar_path'] = $newAvatarPath;
                }

                $current->fill($data);
                if ($emailChanged) {
                    $current->auth_version = (int) $current->auth_version + 1;
                }
                $current->save();

                return [$current, $emailChanged];
            });
        } catch (\Throwable $exception) {
            if ($newAvatarPath !== null) {
                Storage::disk('public')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if ($oldAvatarPath !== null && $newAvatarPath !== null && str_starts_with($oldAvatarPath, 'avatars/')) {
            Storage::disk('public')->delete($oldAvatarPath);
        }

        $response = [
            'data' => [
                'id' => $fresh->getKey(),
                ...$this->profileData($fresh),
                'permissions' => $fresh->grantedPermissions(),
                'roles' => $fresh->roles()->pluck('slug')->values()->all(),
            ],
        ];

        if ($emailChanged) {
            $token = Auth::guard('api')->login($fresh);
            $response['access_token'] = $token;
            $response['expires_in'] = Auth::guard('api')->factory()->getTTL() * 60;
        }

        $this->events->record($emailChanged ? 'account_email_changed' : 'account_updated', $request, $fresh);

        return response()->json($response)->header('Cache-Control', 'no-store');
    }

    public function access(): JsonResponse
    {
        $user = Auth::guard('api')->user();
        return response()->json([
            'data' => $this->diagnostic($user),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function diagnostic(User $user): array
    {
        $granted = $user->grantedPermissions();
        $platformAdmin = $user->roles()->where('slug', 'platform_admin')->exists();
        $missing = $platformAdmin
            ? OperationalAccessCatalog::missingForAdmin($granted) : [];
        return [
            'is_platform_admin' => $platformAdmin,
            'granted_count' => count($granted),
            'expected_admin_count' => count(OperationalAccessCatalog::keys()),
            'missing_admin_permissions' => $missing,
            'admin_role_incomplete' => $platformAdmin && $missing !== [],
            'modules' => OperationalAccessCatalog::modulesFor($granted),
            'financial_operations_enabled' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function profileData(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'company_name' => $user->company_name,
            'avatar_url' => $user->avatar_path !== null
                ? Storage::disk('public')->url($user->avatar_path)
                : null,
        ];
    }

    public function refresh(): JsonResponse
    {
        try {
            // O refresh é permitido durante a janela de renovação mesmo depois
            // do vencimento do access token. O JWTAuth valida a assinatura,
            // a blacklist, a janela de refresh e preserva auth_version.
            $token = JWTAuth::parseToken()->refresh();
            $payload = JWTAuth::setToken($token)->getPayload();
            $user = User::query()->find($payload->get('sub'));
            $version = $payload->get('av');

            if (! $user || ! $user->is_active || ! is_int($version) || $version !== (int) $user->auth_version ||
                (int) $payload->get('tid') !== (int) $user->tenant_id ||
                ! DB::table('tenants')->where('id', $user->tenant_id)->where('is_active', true)->exists()) {
                JWTAuth::setToken($token)->invalidate();

                return response()->json(['message' => 'Sessão inválida ou conta desativada.'], 401)
                    ->header('Cache-Control', 'no-store');
            }
        } catch (JWTException) {
            return response()->json(['message' => 'Token inválido ou expirado.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $this->events->record('token_refreshed', request(), $user);

        return $this->tokenResponse($token);
    }

    public function logout(): JsonResponse
    {
        $user = Auth::guard('api')->user();
        Auth::guard('api')->logout();
        $this->events->record('logout', request(), $user);

        return response()->json(['message' => 'Sessão encerrada.'])
            ->header('Cache-Control', 'no-store');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $data = $request->validated();

        // A atualização da senha, versão de sessão e auditoria são atômicas.
        DB::transaction(function () use ($user, $data, $request): void {
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $tokenVersion = JWTAuth::parseToken()->getPayload()->get('av');

            if (! $current->is_active || ! is_int($tokenVersion) ||
                $tokenVersion !== (int) $current->auth_version) {
                throw new AuthenticationException('Sessão inválida.');
            }

            if (! Hash::check($data['current_password'], $current->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Senha atual incorreta.'],
                ]);
            }

            $current->password = $data['new_password'];
            $current->auth_version = (int) $current->auth_version + 1;
            $current->save();
            $this->events->record('password_changed', $request, $current);
        });

        // A nova auth_version invalida todos os JWTs antigos, mesmo que a blacklist falhe.
        Auth::guard('api')->logout();

        return response()->json([
            'message' => 'Senha alterada. Entre novamente com a nova senha.',
        ])->header('Cache-Control', 'no-store');
    }

    private function tokenResponse(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ])->header('Cache-Control', 'no-store');
    }
}
