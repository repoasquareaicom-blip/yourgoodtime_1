<?php

namespace App\Providers;

use App\Services\Prediction\RuleEngine;
use App\Services\Prediction\Rules\Rule001NakshatraLagnam;
use App\Services\Prediction\Rules\Rule002CurrentMahadasha;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RuleEngine::class, function ($app): RuleEngine {
            return (new RuleEngine())
                ->addRule($app->make(Rule001NakshatraLagnam::class))
                ->addRule($app->make(Rule002CurrentMahadasha::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('internal-mail-otp', function (Request $request): array {
            $email = strtolower(trim((string) $request->input('email', '')));

            $emailKey = $email === ''
                ? 'missing'
                : hash('sha256', $email);

            return [
                // Maximum 20 OTP requests per minute from one IP.
                Limit::perMinute(20)
                    ->by('ip:'.$request->ip()),

                // Maximum 5 OTP requests per email within 10 minutes.
                Limit::perMinutes(10, 5)
                    ->by('email:'.$emailKey),
            ];
        });

        RateLimiter::for('internal-mail-send', function (Request $request): array {
            return [
                Limit::perMinute(40)
                    ->by('ip:'.$request->ip()),
            ];
        });
    }
}
