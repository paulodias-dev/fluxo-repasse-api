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
        RateLimiter::for('vehicle-plate', function (Request $request): array {
            $user = $request->user('api');
            return [
                Limit::perMinute(6)->by('vehicle-plate-user:'.$user?->tenant_id.':'.$user?->id),
                Limit::perHour(30)->by('vehicle-plate-tenant:'.$user?->tenant_id),
            ];
        });
        RateLimiter::for('vehicle-catalog', function (Request $request): array {
            $user = $request->user('api');
            return [
                Limit::perMinute(40)->by('vehicle-catalog-user:'.$user?->tenant_id.':'.$user?->id),
                Limit::perMinute(120)->by('vehicle-catalog-tenant:'.$user?->tenant_id),
            ];
        });

        RateLimiter::for('repasse-refresh', function (Request $request): Limit {
            $id = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';

            return Limit::perMinute(30)->by('refresh:'.$id);
        });

        RateLimiter::for('repasse-password', function (Request $request): Limit {
            $userId = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';

            return Limit::perMinute(3)->by('password:'.$userId);
        });

        RateLimiter::for('repasse-admin-read', function (Request $request): Limit {
            $userId = $request->user('api')?->getAuthIdentifier() ?? 'anonymous';

            return Limit::perMinute(90)->by('users-read:'.$userId);
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

        RateLimiter::for('repasse-registration', function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $email = $request->input('email');
            $identity = is_string($email) ? Str::lower(trim($email)) : '__invalid__';

            return [
                Limit::perMinute(5)->by('registration-ip:'.$ip),
                Limit::perHour(10)->by('registration-identity:'.$identity),
            ];
        });

        RateLimiter::for('repasse-verification', function (Request $request): array {
            $ip = $request->ip() ?? 'unknown';
            $email = $request->input('email');

            return [
                Limit::perMinute(10)->by('verification-ip:'.$ip),
                Limit::perMinute(3)->by('verification-email:'.(is_string($email) ? Str::lower(trim($email)) : '__invalid__')),
            ];
        });
    }
}
