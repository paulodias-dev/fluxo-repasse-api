<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\EnsureCurrentSession;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RequestCorrelationId;
use App\Http\Middleware\RequireBearerToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Executa antes do roteamento para correlacionar também 404/405 da API.
        $middleware->prepend(RequestCorrelationId::class);
        $middleware->api(append: [ApiSecurityHeaders::class]);
        // Tenant JWT deve ser verificado ANTES do implicit model binding.
        // Sem esta prioridade, Party $party poderia ser resolvida sem contexto.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            EnsureCurrentSession::class
        );
        $middleware->alias(['bearer.only' => RequireBearerToken::class,
            'session.current' => EnsureCurrentSession::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/*')) {
                return ApiSecurityHeaders::secure($response);
            }

            return $response;
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Não autenticado.'], 401);
            }

            return null;
        });

        $exceptions->render(function (JWTException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Token inválido ou expirado.'], 401);
            }

            return null;
        });

        // Never expose SQL, database hosts or schema details through the API,
        // even if a production environment accidentally enables APP_DEBUG.
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/*')) {
                report($exception);

                return response()->json([
                    'code' => 'database_error',
                    'message' => 'Não foi possível concluir esta operação agora. Tente novamente em instantes.',
                ], 500);
            }

            return null;
        });
    })->create();
