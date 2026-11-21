<?php

namespace App\Services;

use App\Exceptions\SocialOnboardingRequiredException;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

final class SocialAuthService
{
    public function __construct(private readonly AuthEventRecorder $events) {}

    public function authenticate(array $data, ?Request $request = null): User
    {
        try {
            $socialUser = Socialite::driver($data['provider'])
                ->stateless()
                ->userFromToken($data['access_token']);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'provider' => ['Não foi possível autenticar com o Google. Tente novamente.'],
            ]);
        }

        $email = $socialUser->getEmail();
        $providerId = $socialUser->getId();

        if (! is_string($email) || trim($email) === '' || ! is_string($providerId) || trim($providerId) === '') {
            throw ValidationException::withMessages([
                'provider' => ['O Google não informou uma identidade válida para este acesso.'],
            ]);
        }

        if (! $this->googleEmailIsVerified($socialUser)) {
            throw ValidationException::withMessages([
                'provider' => ['O e-mail da conta Google precisa estar verificado.'],
            ]);
        }

        $email = Str::lower(trim($email));
        $account = SocialAccount::query()
            ->where('provider', $data['provider'])
            ->where('provider_id', $providerId)
            ->first();

        $user = $account?->user;
        if (! $user) {
            $user = User::query()->where('email', $email)->first();
        }

        if (! $user && $data['intent'] !== 'register') {
            throw new SocialOnboardingRequiredException();
        }

        $environmentName = $this->socialEnvironmentName(
            $socialUser,
            $email,
            $data['company_name'] ?? null,
        );

        $user = DB::transaction(function () use ($data, $email, $providerId, $socialUser, $user, $request, $environmentName): User {
            if (! $user) {
                $tenant = Tenant::query()->create([
                    'name' => $environmentName,
                    'slug' => $this->uniqueTenantSlug($environmentName),
                    'is_active' => true,
                ]);

                $roles = TenantRoles::provision((int) $tenant->id);
                $user = User::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $this->socialName($socialUser, $email),
                    'email' => $email,
                    'company_name' => $environmentName,
                    'password' => Str::random(64),
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'terms_accepted_at' => now(),
                    'terms_version' => config('app.terms_version'),
                    'avatar_external_url' => $this->safeAvatarUrl($socialUser->getAvatar()),
                ]);
                $user->roles()->sync([$roles['tenant_admin']->id]);
                $this->events->record('account_registered_social', $request, $user);
            } else {
                $updates = [];
                if ($user->email_verified_at === null) {
                    $updates['email_verified_at'] = now();
                }
                if ($user->avatar_external_url === null) {
                    $avatar = $this->safeAvatarUrl($socialUser->getAvatar());
                    if ($avatar !== null) {
                        $updates['avatar_external_url'] = $avatar;
                    }
                }
                if ($updates !== []) {
                    $user->forceFill($updates)->save();
                }
            }

            SocialAccount::query()->updateOrCreate(
                [
                    'provider' => $data['provider'],
                    'provider_id' => $providerId,
                ],
                [
                    'user_id' => $user->getKey(),
                    'provider_email' => $email,
                    'provider_name' => $socialUser->getName(),
                    'provider_avatar' => $this->safeAvatarUrl($socialUser->getAvatar()),
                    'data' => $this->sanitizedSocialData($socialUser->getRaw()),
                ],
            );

            return $user->fresh();
        });

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'provider' => ['Esta conta está inativa. Procure o administrador do ambiente.'],
            ]);
        }

        if (! $user->tenant_id || ! Tenant::query()->whereKey($user->tenant_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'provider' => ['A empresa desta conta não está disponível para acesso.'],
            ]);
        }

        $this->events->record('social_login_succeeded', $request, $user);

        return $user;
    }

    private function googleEmailIsVerified(SocialUser $socialUser): bool
    {
        $raw = $socialUser->getRaw();
        $value = $raw['verified_email'] ?? $raw['email_verified'] ?? null;

        // Google userinfo returns verified_email. The fallback keeps the
        // provider adapter testable while still rejecting an explicit false.
        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function socialName(SocialUser $socialUser, string $email): string
    {
        $name = trim((string) ($socialUser->getName() ?: $socialUser->getNickname() ?: ''));
        return $name !== '' ? Str::limit($name, 255, '') : Str::before($email, '@');
    }

    private function socialEnvironmentName(SocialUser $socialUser, string $email, ?string $provided): string
    {
        $provided = trim((string) $provided);
        if ($provided !== '') {
            return Str::limit($provided, 180, '');
        }

        return Str::limit($this->socialName($socialUser, $email), 180, '');
    }

    private function safeAvatarUrl(?string $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'https' ? Str::limit($url, 2000, '') : null;
    }

    /** @return array<string, mixed> */
    private function sanitizedSocialData(array $data): array
    {
        unset($data['access_token'], $data['refresh_token'], $data['token']);
        return $data;
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'empresa';
        $base = Str::limit($base, 88, '');
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
