<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_loads_demo_data_in_testing_without_environment_flags(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('repasse_cases', 6);
    }
}
