<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('repasse-login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email', '')));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(30)->by('login-ip:'.$ip),
                Limit::perMinute(5)->by('login-identity:'.$email.'|'.$ip),
            ];
        });
    }
}
