<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $requested = $request->header('X-Tenant-ID');
        if ($requested !== null && (! ctype_digit($requested) || (int) $requested < 1)) {
            return response()->json(['message' => 'Empresa selecionada inválida.'], 422);
        }

        $memberships = DB::table('tenant_users as member')
            ->join('tenants as tenant', 'tenant.id', '=', 'member.tenant_id')
            ->where('member.user_id', $user->id)
            ->where('member.is_active', true)->where('tenant.is_active', true)
            ->pluck('tenant.id')->map(static fn ($value): int => (int) $value);
        if ($memberships->isEmpty()) {
            return response()->json(['message' => 'Usuário sem empresa ativa.'], 403);
        }
        // Para contas com múltiplas empresas, a seleção é obrigatória.
        if ($requested === null && $memberships->count() > 1) {
            return response()->json(['message' => 'Selecione uma empresa antes de continuar.', 'code' => 'TENANT_REQUIRED'], 409);
        }
        $selected = $requested === null ? $memberships->first() : (int) $requested;
        if (! $memberships->contains($selected)) {
            return response()->json(['message' => 'Empresa não vinculada a este usuário.'], 403);
        }
        TenantContext::set($request, $selected);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
