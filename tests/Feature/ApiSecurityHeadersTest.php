<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class ApiSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('test-headers-secret-', 4));
    }

    private function assertProtectedHeaders(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_liveness_response_does_not_allow_caching(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();
        $this->assertProtectedHeaders($response);
    }

    public function test_readiness_response_does_not_allow_caching(): void
    {
        $response = $this->getJson('/api/v1/ready')->assertOk();
        $this->assertProtectedHeaders($response);
    }

    public function test_unauthenticated_response_has_security_headers(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertProtectedHeaders($response);
        $response->assertJsonMissingPath('access_token');
    }

    public function test_authenticated_private_profile_has_security_headers(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        $response = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertProtectedHeaders($response);
        $response->assertJsonMissingPath('data.password');
    }

    public function test_forbidden_admin_response_keeps_no_store(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        $response = $this->withToken($token)->getJson('/api/v1/admin/users')
            ->assertForbidden();
        $this->assertProtectedHeaders($response);
    }
}
