<?php

namespace App\Services;

use App\Exceptions\SocialOnboardingRequiredException;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AppleAccountService
{
    public function __construct(private readonly AppleIdentity $identity, private readonly AuthEventRecorder $events) {}

    public function authenticate(array $data, Request $request): User
    {
        $claims = $this->identity->verify($data['identity_token'], $data['nonce']);
        $subject = $claims['sub'];
        try {
            $user = DB::transaction(function () use ($claims, $subject, $data, $request): User {
                $account = SocialAccount::query()->where('provider', 'apple')->where('provider_id', $subject)
                    ->lockForUpdate()->first();
                if ($account) {
                    return $account->user()->firstOrFail();
                }
                if ($data['intent'] !== 'register') {
                    throw new SocialOnboardingRequiredException;
                }
                $email = is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : '';
                if (! in_array($claims['email_verified'] ?? null, [true, 'true'], true)
                    || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                    throw ValidationException::withMessages(['provider' => 'A Apple precisa fornecer um e-mail verificado para o cadastro.']);
                }
                // Jamais vincular um sub novo a uma conta existente apenas pelo e-mail.
                if (User::query()->where('email', $email)->exists()) {
                    throw ValidationException::withMessages(['provider' => 'Use o método de acesso original da conta.']);
                }
                $name = Str::limit(trim((string) ($data['name'] ?? '')) ?: Str::before($email, '@'), 180, '');
                $tenant = Tenant::query()->create([
                    'name' => $name,
                    'slug' => Str::limit(Str::slug($name) ?: 'empresa', 65, '').'-'.Str::lower(Str::random(16)),
                    'is_active' => true,
                ]);
                $roles = TenantRoles::provision((int) $tenant->id);
                $user = User::query()->create([
                    'tenant_id' => $tenant->id, 'name' => $name, 'company_name' => $name,
                    'email' => $email, 'password' => Str::random(64), 'is_active' => true,
                    'email_verified_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => config('app.terms_version'),
                ]);
                $user->roles()->sync([$roles['tenant_admin']->id]);
                app(FinanceCategoryCatalog::class)->ensureForTenant((int) $tenant->id, (int) $user->id);
                SocialAccount::query()->create([
                    'user_id' => $user->id, 'provider' => 'apple', 'provider_id' => $subject,
                    'provider_email' => $email, 'provider_name' => $name, 'data' => [],
                ]);
                $this->events->record('account_registered_social', $request, $user);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['provider' => 'Não foi possível concluir o cadastro. Inicie uma nova tentativa de acesso.']);
        }
        if (! $user->is_active || ! $user->tenant_id || ! Tenant::query()->whereKey($user->tenant_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['provider' => 'Conta ou empresa indisponível.']);
        }
        $this->events->record('social_login_succeeded', $request, $user);

        return $user;
    }
}
