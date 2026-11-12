<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $token = Auth::guard('api')->attempt($request->validated());

        if (! $token) {
            return response()->json(['message' => 'Credenciais inválidas.'], 401)
                ->header('Cache-Control', 'no-store');
        }

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
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function refresh(): JsonResponse
    {
        // Revoga o token anterior por meio da blacklist do jwt-auth.
        return $this->tokenResponse(Auth::guard('api')->refresh());
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Sessão encerrada.'])
            ->header('Cache-Control', 'no-store');
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
