<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        // O escopo permanece restrito à API, apesar do registro global.
        if (! $request->is('api/*')) {
            return $next($request);
        }

        // Não confiar em identificadores fornecidos por clientes.
        $id = (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        $started = hrtime(true);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        if (config('operations.log_api_request_summary', false)) {
            // Somente nome da rota predefinida; nunca URL, query, IP, e-mail, token ou payload.
            Log::info('api.request.completed', [
                'request_id' => $id,
                'method' => $request->method(),
                'route' => $request->route()?->getName() ?? 'unmatched',
                'status' => $response->getStatusCode(),
                'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 1),
            ]);
        }

        return $response;
    }
}
