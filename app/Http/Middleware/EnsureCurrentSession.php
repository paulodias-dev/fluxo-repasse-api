<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $payload = JWTAuth::parseToken()->getPayload();
        $user = User::query()->find($payload->get('sub'));
        $version = $payload->get('av');

        // Qualquer JWT anterior à migration (sem 'av') deve ser recusado.
        if (! $user || ! $user->is_active || ! is_int($version) || $version !== (int) $user->auth_version) {
            return response()->json(['message' => 'Sessão inválida ou conta desativada.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $tenantId = $payload->get('tid');
        if (! is_int($tenantId) || $tenantId <= 0 ||
            (int) $user->tenant_id !== $tenantId ||
            ! Tenant::query()->whereKey($tenantId)->where('is_active', true)->exists()) {
            return response()->json(['message' => 'Sessão da empresa inválida ou desativada.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        // Não aceitar tenant_id arbitrário do cliente, inclusive em headers.
        $request->attributes->set('verified_tenant_id', $tenantId);
        try {
            $response = $next($request);
            if ($response->isSuccessful() && ($request->routeIs('api.v1.operations.vehicles', 'api.v1.cases.show')
                || (! $request->isMethodSafe() && ! $request->is('api/v1/auth/*')))) {
                \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)
                    ->where(fn ($q) => $q->whereNull('last_activity')->orWhere('last_activity', '<', now()->subMinute()))
                    ->update(['last_activity' => now()]);
            }
            return $response;
        } finally {
            $request->attributes->remove('verified_tenant_id');
        }
    }
}
