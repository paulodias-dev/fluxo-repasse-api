<?php

namespace App\Services;

use App\Models\EmailVerificationToken;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Notifications\TenantInvitationNotification;
use App\Notifications\VerifyRegistrationEmail;
use App\Support\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublicRegistrationService
{
    public function __construct(private readonly AuthEventRecorder $events) {}

    /** @return array{user: User, verification_token: string|null} */
    public function register(array $data, ?Request $request = null): array
    {
        $requiresVerification = (bool) config('auth.require_email_verification');

        [$user, $verificationToken] = DB::transaction(function () use ($data, $request, $requiresVerification): array {
            $tenant = Tenant::query()->create([
                'name' => $data['company_name'],
                'slug' => $this->uniqueTenantSlug($data['company_name']),
                'is_active' => true,
            ]);

            $roles = TenantRoles::provision((int) $tenant->id);
            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'company_name' => $data['company_name'],
                'password' => $data['password'],
                'is_active' => ! $requiresVerification,
                'email_verified_at' => $requiresVerification ? null : now(),
                'terms_accepted_at' => now(),
                'terms_version' => config('app.terms_version'),
            ]);
            $user->roles()->sync([$roles['tenant_admin']->id]);

            $token = $requiresVerification ? $this->issueVerificationToken($user) : null;
            $this->events->record('account_registered', $request, $user);

            return [$user, $token];
        });

        if ($verificationToken !== null) {
            $user->notify(new VerifyRegistrationEmail($verificationToken));
        }

        return [
            'user' => $user,
            'verification_token' => $verificationToken,
        ];
    }

    public function verify(string $plainToken, ?Request $request = null): User
    {
        $tokenHash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($tokenHash, $request): User {
            $verification = EmailVerificationToken::query()
                ->where('token_hash', $tokenHash)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->first();

            if (! $verification || $verification->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'token' => ['O link de confirmação é inválido ou expirou.'],
                ]);
            }

            $user = User::query()->whereKey($verification->user_id)->lockForUpdate()->first();
            if (! $user || ! $user->tenant_id) {
                throw ValidationException::withMessages([
                    'token' => ['Não foi possível concluir a confirmação deste cadastro.'],
                ]);
            }

            $verification->used_at = now();
            $verification->save();

            if ($user->email_verified_at === null || ! $user->is_active) {
                $user->email_verified_at = now();
                $user->is_active = true;
                $user->save();
            }

            $this->events->record('email_verified', $request, $user);

            return $user;
        });
    }

    public function resend(string $email, ?Request $request = null): void
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user || $user->email_verified_at !== null || ! $user->tenant_id) {
            return;
        }

        $token = DB::transaction(function () use ($user, $request): string {
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($current->email_verified_at !== null) {
                return '';
            }

            $plainToken = $this->issueVerificationToken($current);
            $this->events->record('verification_resent', $request, $current);

            return $plainToken;
        });

        if ($token !== '') {
            $user->notify(new VerifyRegistrationEmail($token));
        }
    }

    /** @return array{invitation: TenantInvitation, token: string} */
    public function invite(array $data, User $actor, ?Request $request = null): array
    {
        [$invitation, $token] = DB::transaction(function () use ($data, $actor, $request): array {
            TenantInvitation::query()
                ->where('tenant_id', $actor->tenant_id)
                ->where('email', $data['email'])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plainToken = Str::random(64);
            $invitation = TenantInvitation::query()->create([
                'tenant_id' => $actor->tenant_id,
                'invited_by' => $actor->getKey(),
                'role_id' => $data['role_id'],
                'email' => $data['email'],
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addDays(7),
            ]);

            $this->events->record('tenant_invitation_created', $request, null, $actor);

            return [$invitation, $plainToken];
        });

        $tenant = Tenant::query()->findOrFail($actor->tenant_id);
        $notifiable = new User(['email' => $invitation->email, 'name' => $invitation->email]);
        $notifiable->notify(new TenantInvitationNotification($invitation, $token, $tenant->name));

        return compact('invitation', 'token');
    }

    public function acceptInvitation(string $plainToken, array $data, ?Request $request = null): User
    {
        return DB::transaction(function () use ($plainToken, $data, $request): User {
            $invitation = TenantInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'token' => ['O convite é inválido, foi revogado ou expirou.'],
                ]);
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => ['Este e-mail já possui acesso a um ambiente. Entre com sua conta ou use outro e-mail.'],
                ]);
            }

            $tenant = Tenant::query()->whereKey($invitation->tenant_id)->where('is_active', true)->first();
            $role = Role::query()->whereKey($invitation->role_id)
                ->where('tenant_id', $invitation->tenant_id)->first();
            if (! $tenant || ! $role || $role->slug === 'platform_admin') {
                throw ValidationException::withMessages([
                    'token' => ['O convite não está mais disponível.'],
                ]);
            }

            $user = User::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'email' => $invitation->email,
                'company_name' => $tenant->name,
                'password' => $data['password'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $user->roles()->sync([$role->id]);

            $invitation->accepted_at = now();
            $invitation->save();
            $this->events->record('tenant_invitation_accepted', $request, $user, null);

            return $user;
        });
    }

    private function issueVerificationToken(User $user): string
    {
        EmailVerificationToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plainToken = Str::random(64);
        EmailVerificationToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        return $plainToken;
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
