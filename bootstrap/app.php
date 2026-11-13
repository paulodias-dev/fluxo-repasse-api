<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\EnsureCurrentSession;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RequestCorrelationId;
use App\Http\Middleware\RequireBearerToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [RequestCorrelationId::class]);
        $middleware->api(append: [ApiSecurityHeaders::class]);
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
    })->create();
