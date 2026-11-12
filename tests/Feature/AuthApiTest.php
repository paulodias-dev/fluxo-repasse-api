<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertHeader('Cache-Control', 'no-store');

        $this->withToken($response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
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
}
