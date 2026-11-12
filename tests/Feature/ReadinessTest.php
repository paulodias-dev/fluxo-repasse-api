<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_returns_success_after_migrations(): void
    {
        $this->getJson('/api/v1/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'service' => 'fluxo-repasse-api',
                'version' => 'v1',
            ])
            ->assertHeader('Cache-Control', 'no-store');
    }

    public function test_missing_required_table_returns_generic_503(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('users')->andReturn(false);

        $this->getJson('/api/v1/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'service' => 'fluxo-repasse-api',
                'version' => 'v1',
            ])
            ->assertDontSee('users')
            ->assertHeader('Cache-Control', 'no-store');
    }

    public function test_database_exception_does_not_leak_connection_details(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')
            ->andThrow(new RuntimeException('sensitive-host.internal:3306 database password'));

        $response = $this->getJson('/api/v1/ready')->assertStatus(503);
        $response->assertJsonPath('status', 'unavailable');
        $response->assertDontSee('sensitive-host.internal');
        $response->assertDontSee('password');
    }

    public function test_existing_liveness_endpoint_is_independent_of_database(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
