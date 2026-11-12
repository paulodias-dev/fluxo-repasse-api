<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [\App\Http\Middleware\ApiSecurityHeaders::class]);
        $middleware->alias(['bearer.only' => \App\Http\Middleware\RequireBearerToken::class,
            'session.current' => \App\Http\Middleware\EnsureCurrentSession::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response {
            if (request()->is('api/*')) {
                return \App\Http\Middleware\ApiSecurityHeaders::secure($response);
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
