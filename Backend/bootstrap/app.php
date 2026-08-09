<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureMailGatewayKey;
use App\Http\Middleware\EnsureAdminUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(HandleCors::class);
        $middleware->statefulApi();
        $middleware->alias([
            'mail.gateway' => EnsureMailGatewayKey::class,
            'admin' => EnsureAdminUser::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/admin/*',
            'api/astrology/sessions',
            'api/astrology/places/select',
            'api/predictions/past',
            'api/predictions/result',
            'api/predictions/*/feedback',
            'api/payments/future-prediction/order',
            'api/payments/temple-remedy/order',
            'api/payments/astrologer-appointment/order',
            'api/payments/razorpay/verify',
            'api/astrologer-appointments',
            'api/astrologer-appointments/*/cancel',
            'api/temple-remedies',
            'api/temple-remedies/*/cancel-payment',
            'api/temple-remedies/*/feedback',
            'api/webhooks/razorpay',
            'api/auth/login',
            'api/auth/logout',
            'api/auth/profile',
            'api/auth/send-otp',
            'api/auth/validate-otp',
            'api/auth/verify-otp',
            'api/internal/mail/send-otp',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $exception, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });
    })->create();
