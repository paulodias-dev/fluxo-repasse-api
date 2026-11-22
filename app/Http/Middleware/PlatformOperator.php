<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class PlatformOperator
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user('api')->roles()->whereNull('roles.tenant_id')
            ->where('roles.slug', 'platform_admin')->exists(), 403);
        return $next($request);
    }
}
