<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\User;
use App\Services\AuthEventRecorder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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

        $this->events->record('login_succeeded', $request, Auth::guard('api')->user());

        return $this->tokenResponse($token);
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        return response()->json([
            'data' => [
                'id' => $user->getAuthIdentifier(),
                'name' => $user->name,
                'email' => $user->email,
                'permissions' => $user->grantedPermissions(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function refresh(): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $token = Auth::guard('api')->refresh();
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
