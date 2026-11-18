<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TenantSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_seed_command_creates_tenant_admin_and_is_idempotent(): void
    {
        $this->artisan('repasse:tenant:seed')->assertExitCode(0);
        $this->artisan('repasse:tenant:seed')->assertExitCode(0);

        $tenant = Tenant::query()->where('slug', 'fluxo-repasse')->firstOrFail();
        $admin = User::query()->where('email', 'admin.repasse@fluxosistemas.com.br')->firstOrFail();

        $this->assertSame('Fluxo Repasse', $tenant->name);
        $this->assertSame($tenant->id, $admin->tenant_id);
        $this->assertTrue(Hash::check('Tki&rn3233', (string) $admin->getRawOriginal('password')));
        $this->assertTrue($admin->roles()->where('slug', 'tenant_admin')->exists());
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
    }
}
