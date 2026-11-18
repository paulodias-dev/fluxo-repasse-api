<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placed before SubstituteBindings: scoped Eloquent route model binding MUST
 * run with the tenant already selected from the authenticated account.
 */
final class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        // Contexto por requisição. Testes de integração podem preservar o
        // contexto fixture anterior para montar dados após a requisição.
        $context = app(TenantContext::class);
        $previous = $context->current();
        $context->clear();
        // These routes do not access tenant business data. Auth identity is
        // globally unique by email; tenant is resolved after successful login.
        if ($request->is(
            'api/v1/health',
            'api/v1/ready',
            'api/v1/auth/*',
        )) {
            return $next($request);
        }

        $user = Auth::guard('api')->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $tenant = $user->tenant_id
            ? DB::table('tenants')->whereKey($user->tenant_id)->where('is_active', true)->first(['id'])
            : null;

        if (! $user->is_active || ! $tenant) {
            return response()->json([
                'message' => 'Conta sem empresa ativa vinculada. Acesso aos dados bloqueado.',
            ], 403)->header('Cache-Control', 'no-store');
        }

        $context->set((int) $tenant->id);

        try {
            return $next($request);
        } finally {
            $context->clear();
            if (app()->runningUnitTests() && $previous !== null) {
                $context->set($previous);
            }
        }
    }
}
