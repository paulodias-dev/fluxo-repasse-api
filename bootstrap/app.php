<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Middlewares de autenticação e autorização serão definidos com o domínio.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Tratamento de erros será padronizado junto aos contratos OpenAPI.
    })->create();
