<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\OperationalAcceptance;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OperationalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.blacklist_enabled', true);
        config()->set('jwt.secret', str_repeat('read-only-qa-secret-', 4));
    }

    private function provisionAdmin(): User
    {
        $this->seed(AdminUserSeeder::class);
        $role = Role::query()->where('slug', 'platform_admin')->firstOrFail();
        $user = User::factory()->create();
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_read_only_acceptance_passes_with_all_migrations_catalog_and_active_admin(): void
    {
        $user = $this->provisionAdmin();
        $rolesBefore = Role::query()->count();
        $permissionsBefore = Permission::query()->count();
        $authVersion = $user->auth_version;
        $result = app(OperationalAcceptance::class)->inspect();

        $this->assertTrue($result['ready'], json_encode($result['checks']));
        $this->assertTrue($result['details']['module_tables_ready']);
        $this->assertSame([], $result['details']['pending_migrations']);
        $this->assertSame([], $result['details']['missing_permissions']);
        $this->assertSame([], $result['details']['missing_admin_grants']);
        $this->assertSame(1, $result['details']['active_admin_count']);
        $this->assertSame('read_only', $result['mode']);
        $this->assertFalse($result['financial_payment_enabled']);
        $this->assertFalse($result['tested_runtime']);

        $this->artisan('repasse:acceptance --json')->assertExitCode(0);
        $this->assertSame($rolesBefore, Role::query()->count());
        $this->assertSame($permissionsBefore, Permission::query()->count());
        $this->assertSame($authVersion, $user->refresh()->auth_version);
    }

    public function test_acceptance_fails_closed_when_permission_catalog_or_admin_is_missing(): void
    {
        $this->provisionAdmin();
        Permission::query()->where('key', 'packages.manage')->delete();
        $result = app(OperationalAcceptance::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertContains('packages.manage', $result['details']['missing_permissions']);
        $this->assertContains('packages.manage', $result['details']['missing_admin_grants']);
        $this->artisan('repasse:acceptance --json')->assertExitCode(1);
        $this->assertDatabaseMissing('permissions', ['key' => 'packages.manage']);
    }

    public function test_acceptance_denies_when_no_active_admin_remains(): void
    {
        $user = $this->provisionAdmin();
        $user->forceFill(['is_active' => false])->save();
        $result = app(OperationalAcceptance::class)->inspect();

        $this->assertFalse($result['ready']);
        $this->assertSame(0, $result['details']['active_admin_count']);
        $this->artisan('repasse:acceptance')->assertExitCode(1);
    }

    public function test_acceptance_detects_missing_jwt_revocation_setting(): void
    {
        $this->provisionAdmin();
        config()->set('jwt.blacklist_enabled', false);
        $result = app(OperationalAcceptance::class)->inspect();

        $this->assertFalse($result['ready']);
        $jwtCheck = collect($result['checks'])->firstWhere('id', 'jwt_revocation');
        $this->assertSame(false, $jwtCheck['ok']);
    }

    public function test_acceptance_output_contains_no_user_identity_or_credentials(): void
    {
        $user = $this->provisionAdmin();
        $result = app(OperationalAcceptance::class)->inspect();
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($user->email, $json);
        $this->assertStringNotContainsString($user->name, $json);
        $this->assertStringNotContainsString((string) config('jwt.secret'), $json);
        $this->assertArrayNotHasKey('users', $result['details']);
        $this->assertSame('fluxo-repasse-operational-acceptance-v1', $result['schema']);
    }
}
