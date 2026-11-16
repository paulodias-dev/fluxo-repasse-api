<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\OperationalAccessCatalog;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class OperationalAccessRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('operational-access-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function makeUser(array $permissions): User
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'operator_'.$user->id, 'name' => 'Operador QA']);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $user;
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_seeder_recovers_existing_platform_admin_but_never_promotes_unrelated_user(): void
    {
        $admin = $this->makeUser([]);
        $limited = $this->makeUser(['users.manage']);
        $role = Role::query()->create(['slug' => 'platform_admin', 'name' => 'Admin antigo']);
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $userPermission = Permission::query()->firstOrCreate(['key' => 'users.manage']);
        $role->permissions()->attach($userPermission->id);
        $initialVersion = (int) $admin->auth_version;

        $this->seed(AdminUserSeeder::class);
        $admin->refresh();
        $this->assertCount(7, $admin->grantedPermissions());
        $this->assertSame(
            [], OperationalAccessCatalog::missingForAdmin($admin->grantedPermissions())
        );
        $this->assertSame($initialVersion + 1, (int) $admin->auth_version);
        $this->assertEqualsCanonicalizing(
            ['users.manage'], $limited->fresh()->grantedPermissions()
        );
        $this->assertFalse($limited->fresh()->roles()->where('slug', 'platform_admin')->exists());
        $this->seed(AdminUserSeeder::class);
        $this->assertSame($initialVersion + 1, (int) $admin->fresh()->auth_version);
    }

    public function test_authenticated_profile_exposes_real_permissions_and_missing_admin_modules(): void
    {
        $admin = $this->makeUser([]);
        $role = Role::query()->create(['slug' => 'platform_admin', 'name' => 'Admin incompleto']);
        $admin->roles()->attach($role->id);
        $permission = Permission::query()->firstOrCreate(['key' => 'users.manage']);
        $role->permissions()->attach($permission->id);
        $token = $this->login($admin);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonCount(1, 'data.permissions')
            ->assertJsonPath('data.access_diagnostic.admin_role_incomplete', true)
            ->assertJsonPath('data.access_diagnostic.is_platform_admin', true)
            ->assertJsonPath('data.access_diagnostic.expected_admin_count', 7)
            ->assertJsonPath('data.access_diagnostic.modules.0.available', true)
            ->assertJsonPath('data.access_diagnostic.modules.3.available', false);

        $this->withToken($token)->getJson('/api/v1/auth/access')
            ->assertOk()->assertJsonPath('data.admin_role_incomplete', true)
            ->assertJsonPath('data.financial_operations_enabled', false);

        $this->seed(AdminUserSeeder::class);
        // Role permissions changed: stale JWT must be rejected, not silently upgraded.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $freshToken = $this->login($admin);
        $this->withToken($freshToken)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonCount(7, 'data.permissions')
            ->assertJsonPath('data.access_diagnostic.admin_role_incomplete', false);
    }

    public function test_limited_user_sees_only_real_grants_and_cannot_repair_admin_role(): void
    {
        $operator = $this->makeUser(['users.manage']);
        $token = $this->login($operator);
        $this->withToken($token)->getJson('/api/v1/auth/access')
            ->assertOk()
            ->assertJsonPath('data.is_platform_admin', false)
            ->assertJsonPath('data.admin_role_incomplete', false);
        $status = Artisan::call('repasse:access:doctor', [
            'email' => $operator->email, '--repair' => true, '--yes' => true
        ]);
        $this->assertSame(1, $status);
        $this->assertCount(1, $operator->fresh()->grantedPermissions());
        $this->assertFalse($operator->fresh()->roles()->where('slug', 'platform_admin')->exists());
    }

    public function test_bootstrap_grants_complete_technical_role_only_when_explicitly_requested(): void
    {
        $existing = $this->makeUser([]);
        $other = $this->makeUser(['users.manage']);
        $status = Artisan::call('repasse:access:bootstrap', [
            'email' => $existing->email, '--yes' => true,
        ]);
        $this->assertSame(0, $status);
        $this->assertTrue($existing->fresh()->roles()->where('slug', 'platform_admin')->exists());
        $this->assertCount(7, $existing->fresh()->grantedPermissions());
        $this->assertCount(1, $other->fresh()->grantedPermissions());
        $this->assertSame(0, Artisan::call('repasse:access:doctor', [
            'email' => $existing->email, '--json' => true,
        ]));
        $this->assertCount(7, $existing->fresh()->grantedPermissions());
    }

    public function test_repair_only_syncs_existing_admin_and_never_creates_account(): void
    {
        $user = $this->makeUser([]);
        $role = Role::query()->create(['slug' => 'platform_admin', 'name' => 'Admin incompleto']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'users.manage'])->id);
        $user->roles()->attach($role->id);

        $status = Artisan::call('repasse:access:doctor', [
            'email' => $user->email, '--repair' => true, '--yes' => true,
        ]);
        $this->assertSame(0, $status);
        $this->assertCount(7, $user->fresh()->grantedPermissions());
        $this->assertDatabaseCount('users', 1);
    }
}
