<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer [^\\s]+$/i', $header)) {
            return response()->json(['message' => 'Token Bearer obrigatório.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
