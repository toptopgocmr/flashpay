<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => \App\Http\Middleware\EnsureActiveAccount::class,
            'pin' => \App\Http\Middleware\RequirePin::class,
            'idempotent' => \App\Http\Middleware\Idempotent::class,
            'merchant.api' => \App\Http\Middleware\AuthenticateMerchantApi::class,
        ]);

        // Page de checkout e-commerce (formulaire sans session, protégé par OTP + PIN)
        $middleware->validateCsrfTokens(except: ['checkout/*']);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Services\Peex\PeexException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'peex' => $e->body], 422);
            }
        });
        $exceptions->render(function (\App\Exceptions\InsufficientFundsException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
        $exceptions->render(function (\App\Exceptions\BusinessException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode] + $e->extra, $e->status);
            }
        });
        $exceptions->render(function (\App\Exceptions\CashNetworkException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }
        });
    })->create();
