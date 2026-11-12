<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('api')->user();
        $version = JWTAuth::parseToken()->getPayload()->get('av');

        // Qualquer JWT anterior à migration (sem 'av') deve ser recusado.
        if (! $user || ! $user->is_active || ! is_int($version) || $version !== (int) $user->auth_version) {
            return response()->json(['message' => 'Sessão inválida ou conta desativada.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
