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
        RateLimiter::for('repasse-refresh', function (Request $request): Limit {
            $id = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';
            return Limit::perMinute(30)->by('refresh:'.$id);
        });

        RateLimiter::for('repasse-password', function (Request $request): Limit {
            $userId = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';

            return Limit::perMinute(3)->by('password:'.$userId);
        });

        RateLimiter::for('repasse-admin-write', function (Request $request): Limit {
            $id = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';
            return Limit::perMinute(30)->by('users-write:'.$id);
        });

        RateLimiter::for('repasse-login', function (Request $request): array {
            $input = $request->input('email');
            $email = is_string($input) ? Str::lower(trim($input)) : '__invalid__';
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perMinute(30)->by('login-ip:'.$ip),
                Limit::perMinute(5)->by('login-identity:'.$email.'|'.$ip),
            ];
        });
    }
}
