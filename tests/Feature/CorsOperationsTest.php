<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CorsOperationsTest extends TestCase
{
    public function test_administrative_patch_is_allowed_by_cors_policy(): void
    {
        $this->assertContains('PATCH', config('cors.allowed_methods'));

        $response = $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/admin/users/1/status');

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertStringContainsString('PATCH', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_request_id_is_visible_to_the_browser_without_exposing_tokens(): void
    {
        $this->assertContains('X-Request-ID', config('cors.exposed_headers'));
        $this->assertNotContains('Authorization', config('cors.exposed_headers'));

        $response = $this->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/health')->assertOk();

        $this->assertStringContainsString('X-Request-ID', (string) $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }
}
