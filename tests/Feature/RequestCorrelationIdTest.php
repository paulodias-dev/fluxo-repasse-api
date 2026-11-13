<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

final class RequestCorrelationIdTest extends TestCase
{
    public function test_public_request_gets_new_server_generated_id(): void
    {
        $first = $this->getJson('/api/v1/health')->assertOk();
        $second = $this->getJson('/api/v1/health')->assertOk();

        $id = $first->headers->get('X-Request-ID');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id);
        $this->assertNotSame($id, $second->headers->get('X-Request-ID'));
    }

    public function test_client_cannot_force_correlator_id(): void
    {
        $response = $this->withHeaders(['X-Request-ID' => "client-supplied-request-id"])
            ->getJson('/api/v1/health')->assertOk();

        $this->assertNotSame("client-supplied-request-id", $response->headers->get('X-Request-ID'));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }

    public function test_unauthorized_json_response_gets_same_privacy_headers_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
        $response->assertJsonMissingPath('access_token');
    }

    public function test_telemetry_is_disabled_by_default(): void
    {
        config()->set('operations.log_api_request_summary', false);
        Log::spy();

        $this->getJson('/api/v1/health')->assertOk();

        Log::shouldNotHaveReceived('info');
    }

    public function test_opt_in_summary_contains_only_route_metadata(): void
    {
        config()->set('operations.log_api_request_summary', true);
        Log::shouldReceive('info')->once()->with(
            'api.request.completed',
            Mockery::on(function (array $context): bool {
                $keys = array_keys($context);
                sort($keys);
                $this->assertSame(
                    ['duration_ms', 'method', 'request_id', 'route', 'status'],
                    $keys
                );

                return $context['route'] === 'api.v1.health'
                    && $context['method'] === 'GET'
                    && $context['status'] === 200
                    && is_string($context['request_id'])
                    && is_numeric($context['duration_ms']);
            })
        );

        $this->withHeaders(['Authorization' => 'Bearer secret-should-not-be-logged'])
            ->getJson('/api/v1/health')->assertOk();
    }
}
