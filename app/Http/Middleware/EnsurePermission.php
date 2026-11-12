<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user('api');

        if (! $user || ! $user->is_active || ! $user->hasPermission($permission)) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403)
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
