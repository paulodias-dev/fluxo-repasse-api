<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiErrorCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function assertPrivateErrorHeaders($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $id = $response->headers->get('X-Request-ID');
        $this->assertIsString($id);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $id
        );
    }

    public function test_unknown_api_route_returns_private_json_404_with_server_request_id(): void
    {
        $response = $this->getJson('/api/v1/route-does-not-exist')->assertNotFound();

        $this->assertPrivateErrorHeaders($response);
        $response->assertJsonMissingPath('access_token');
    }

    public function test_wrong_http_method_returns_private_405_with_request_id(): void
    {
        $response = $this->putJson('/api/v1/health')->assertStatus(405);

        $this->assertPrivateErrorHeaders($response);
    }

    public function test_unauthenticated_profile_returns_private_401_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertPrivateErrorHeaders($response);
    }

    public function test_invalid_login_payload_returns_private_422_with_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertUnprocessable();

        $this->assertPrivateErrorHeaders($response);
        $response->assertJsonMissingPath('access_token');
    }

    public function test_private_404_ids_are_not_copied_from_untrusted_client(): void
    {
        $clientProvided = 'client-selected-id';
        $response = $this->withHeader('X-Request-ID', $clientProvided)
            ->getJson('/api/v1/not-registered')->assertNotFound();

        $this->assertPrivateErrorHeaders($response);
        $this->assertNotSame($clientProvided, $response->headers->get('X-Request-ID'));
    }

    public function test_cors_preflight_has_private_headers_and_correlation_id(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/admin/users/1/status');

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertPrivateErrorHeaders($response);
        $this->assertStringContainsString(
            'PATCH',
            (string) $response->headers->get('Access-Control-Allow-Methods')
        );
    }

    public function test_unrelated_health_route_is_not_affected_by_api_only_correlation(): void
    {
        // /up is the framework's health route, outside the API namespace.
        $response = $this->get('/up')->assertOk();

        $this->assertFalse($response->headers->has('X-Request-ID'));
    }
}
