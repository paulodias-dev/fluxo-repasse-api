<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_public_registration_remains_disabled(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
        $this->postJson('/api/v1/repasses', [])->assertNotFound();
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
