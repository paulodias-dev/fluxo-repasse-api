<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ApiHealthTest extends TestCase
{
    public function test_health_check_returns_only_public_service_metadata(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'fluxo-repasse-api',
                'version' => 'v1',
            ])
            ->assertHeader('Cache-Control', 'no-store');
    }

    public function test_financial_routes_are_not_published_before_domain_approval(): void
    {
        $this->postJson('/api/v1/repasses', [])->assertNotFound();
    }
}
