<?php

namespace Tests\Feature;

use App\Models\EmailVerificationToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\VerifyRegistrationEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

final class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('integration-test-only-', 3));
        config()->set('jwt.blacklist_enabled', true);
    }

    public function test_login_and_authenticated_profile(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_authenticated_user_can_update_profile_and_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        $response = $this->withToken($token)->post('/api/v1/auth/profile', [
            'name' => 'Administrador do Repasse',
            'email' => 'admin.updated@example.test',
            'phone' => '(82) 99999-9999',
            'company_name' => 'Fluxo Sistemas',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 512, 512),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['name', 'email', 'phone', 'company_name', 'avatar_url'], 'access_token', 'expires_in'])
            ->assertJsonPath('data.name', 'Administrador do Repasse')
            ->assertJsonPath('data.email', 'admin.updated@example.test');

        $updated = $user->fresh();
        $this->assertNotNull($updated?->avatar_path);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone' => '(82) 99999-9999',
            'company_name' => 'Fluxo Sistemas',
        ]);
        Storage::disk('public')->assertExists($updated->avatar_path);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($response->json('access_token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.avatar_url', $response->json('data.avatar_url'));
    }

    public function test_invalid_credentials_do_not_create_session(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'invalid-secret',
        ])->assertUnauthorized()->assertJsonPath('message', 'Credenciais inválidas.');
    }

    public function test_private_endpoints_require_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh')->assertUnauthorized();
    }

    public function test_logout_invalidates_previous_token(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->json('access_token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_public_registration_creates_isolated_tenant_and_owner(): void
    {
        Notification::fake();
        config()->set('auth.require_email_verification', true);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Proprietário da Revenda',
            'company_name' => 'Revenda Teste',
            'email' => 'owner@revenda.test',
            'password' => 'Senha-Forte-123!',
            'password_confirmation' => 'Senha-Forte-123!',
            'terms_accepted' => true,
        ])->assertCreated()->assertJsonPath('email', 'owner@revenda.test');

        $user = User::query()->where('email', 'owner@revenda.test')->firstOrFail();
        $this->assertDatabaseHas('tenants', [
            'id' => $user->tenant_id,
            'name' => 'Revenda Teste',
            'is_active' => true,
        ]);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->roles()->where('slug', 'tenant_admin')->exists());
        Notification::assertSentTo($user, VerifyRegistrationEmail::class);
        $this->assertSame(201, $response->status());
    }

    public function test_public_registration_can_issue_a_session_without_email_confirmation(): void
    {
        Notification::fake();
        config()->set('auth.require_email_verification', false);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Proprietário da Revenda',
            'company_name' => 'Revenda Rápida',
            'email' => 'owner@rapida.test',
            'password' => 'Senha-Forte-123!',
            'password_confirmation' => 'Senha-Forte-123!',
            'terms_accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('requires_email_verification', false)
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $user = User::query()->where('email', 'owner@rapida.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        Notification::assertNothingSent();

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'owner@rapida.test');
    }

    public function test_public_registration_requires_confirmation_before_login(): void
    {
        config()->set('auth.require_email_verification', true);
        $user = User::factory()->unverified()->create([
            'is_active' => false,
            'password' => 'Strong-Passphrase-123!',
        ]);
        $plainToken = 'verification-token-for-test-1234567890';
        EmailVerificationToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertForbidden();

        // Uma conta pública bloqueada não revela o estado do cadastro através
        // da tentativa de login; a confirmação a torna elegível para entrar.
        $this->postJson('/api/v1/auth/verify-email', ['token' => $plainToken])
            ->assertOk();
        $this->assertTrue((bool) $user->fresh()->is_active);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk();
    }

    public function test_google_registration_creates_verified_account_and_session(): void
    {
        $socialUser = new SocialiteUser;
        $socialUser->setRaw([
            'id' => 'google-fluxo-123',
            'verified_email' => true,
            'token' => 'provider-raw-token',
            'refresh_token' => 'provider-raw-refresh',
        ]);
        $socialUser->map([
            'id' => 'google-fluxo-123',
            'name' => 'Google Fluxo',
            'email' => 'google@fluxo.test',
            'avatar' => 'https://example.com/google-avatar.jpg',
        ]);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('userFromToken')
            ->with('google-access-token-123456')
            ->andReturn($socialUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'access_token' => 'google-access-token-123456',
            'intent' => 'register',
            'company_name' => 'Revenda Google',
            'terms_accepted' => true,
        ])->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_in']);

        $user = User::query()->where('email', 'google@fluxo.test')->firstOrFail();
        $account = SocialAccount::query()->where('provider_id', 'google-fluxo-123')->firstOrFail();

        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($user->id, $account->user_id);
        $this->assertArrayNotHasKey('token', $account->data ?? []);
        $this->assertArrayNotHasKey('refresh_token', $account->data ?? []);
        $this->assertDatabaseHas('tenants', ['id' => $user->tenant_id, 'name' => 'Revenda Google']);

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'google@fluxo.test')
            ->assertJsonPath('data.email_verified_at', fn ($value): bool => is_string($value));
    }

    public function test_refresh_rotates_and_invalidates_old_token(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $oldToken = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->json('access_token');

        $newToken = $this->withToken($oldToken)
            ->postJson('/api/v1/auth/refresh')
            ->assertOk()
            ->json('access_token');

        $this->assertNotSame($oldToken, $newToken);

        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_login_is_throttled_after_repeated_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Senha-invalida',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Senha-invalida',
        ])->assertStatus(429);
    }

    public function test_token_in_query_string_does_not_authenticate(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->json('access_token');

        $this->getJson('/api/v1/auth/me?token='.urlencode($token))->assertUnauthorized();
    }
}
