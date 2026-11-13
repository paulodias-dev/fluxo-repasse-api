<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminSeederSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_does_not_create_any_user_or_static_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('roles', ['slug' => 'platform_admin']);
        $this->assertDatabaseHas('permissions', ['key' => 'users.manage']);
        $this->assertDatabaseHas('permissions', ['key' => 'parties.manage']);
        $this->assertTrue(Role::query()->where('slug', 'platform_admin')->firstOrFail()
            ->permissions()->where('key', 'users.manage')->exists());
    }

    public function test_seeder_is_idempotent_without_creating_accounts(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('permissions', 2);
    }
}
