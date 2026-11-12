<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('admin-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        config()->set('jwt.persistent_claims', ['av']);
    }

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function admin(): User
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->firstOrCreate(['slug' => 'platform_admin'], ['name' => 'Administrador técnico']);
        $permission = Permission::query()->firstOrCreate(['key' => 'users.manage']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    public function test_public_and_non_admin_access_are_denied(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();

        $ordinary = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $this->withToken($this->token($ordinary))->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/users', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/'.$ordinary->id, ['name' => 'Invasor'])
            ->assertForbidden();
    }

    public function test_profile_exposes_only_explicit_technical_permissions(): void
    {
        $admin = $this->admin();
        $token = $this->token($admin);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions.0', 'users.manage')
            ->assertJsonMissingPath('data.password');
    }

    public function test_admin_can_list_and_search_without_passwords_or_internal_claims(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Marina Silva', 'email' => 'marina@example.test']);

        $this->withToken($this->token($admin))
            ->getJson('/api/v1/admin/users?search=marina')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Marina Silva')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.auth_version');
    }

    public function test_admin_can_create_user_without_granting_roles_or_financial_permissions(): void
    {
        $admin = $this->admin();
        $this->withToken($this->token($admin))
            ->postJson('/api/v1/admin/users', [
                'name' => 'Marina Silva',
                'email' => '  MARINA@EXAMPLE.TEST ',
                'password' => 'Another-Strong-Password-234!',
                'password_confirmation' => 'Another-Strong-Password-234!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'marina@example.test');

        $created = User::query()->where('email', 'marina@example.test')->firstOrFail();
        $this->assertFalse($created->hasPermission('users.manage'));
        $this->assertSame(0, $created->roles()->count());

        $this->assertDatabaseHas('auth_security_events', [
            'actor_user_id' => $admin->id,
            'user_id' => $created->id,
            'event_type' => 'account_created',
        ]);
    }

    public function test_admin_rejects_weak_or_duplicate_user_credentials(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'existing@example.test']);
        $token = $this->token($admin);

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Outra Pessoa',
            'email' => 'existing@example.test',
            'password' => 'Another-Strong-Password-234!',
            'password_confirmation' => 'Another-Strong-Password-234!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Outra Pessoa',
            'email' => 'new@example.test',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_admin_editing_user_does_not_grant_privileges(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->withToken($this->token($admin))->patchJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Nome Atualizado',
            'email' => 'alterado@example.test',
            'roles' => ['platform_admin'],
        ])->assertOk()->assertJsonPath('data.name', 'Nome Atualizado');

        $this->assertSame('alterado@example.test', $target->fresh()->email);
        $this->assertSame(0, $target->roles()->count());
        $this->assertDatabaseHas('auth_security_events', [
            'actor_user_id' => $admin->id, 'user_id' => $target->id,
            'event_type' => 'account_updated',
        ]);
    }

    public function test_deactivation_invalidates_existing_token_and_records_actor(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $targetToken = $this->token($target);

        $this->withToken($this->token($admin))
            ->patchJson('/api/v1/admin/users/'.$target->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)
            ->assertJsonPath('changed', true);

        $this->withToken($targetToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(2, $target->fresh()->auth_version);
        $this->assertDatabaseHas('auth_security_events', [
            'actor_user_id' => $admin->id,
            'user_id' => $target->id,
            'event_type' => 'account_deactivated',
        ]);
    }

    public function test_admin_cannot_disable_self_or_another_admin_via_http(): void
    {
        $admin = $this->admin();
        $anotherAdmin = $this->admin();
        $token = $this->token($admin);

        $this->withToken($token)
            ->patchJson('/api/v1/admin/users/'.$admin->id.'/status', ['is_active' => false])
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson('/api/v1/admin/users/'.$anotherAdmin->id.'/status', ['is_active' => false])
            ->assertUnprocessable();

        $this->assertTrue($anotherAdmin->fresh()->is_active);
    }

    public function test_cli_bootstrap_requires_existing_user_and_grants_only_technical_role(): void
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);

        $this->artisan('repasse:access:bootstrap', [
            'email' => $user->email, '--yes' => true,
        ])->assertExitCode(0);

        $this->assertTrue($user->fresh()->hasPermission('users.manage'));
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $user->id, 'event_type' => 'platform_admin_granted',
        ]);
    }

    public function test_cli_refuses_to_disable_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->artisan('repasse:user:status', [
            'email' => $admin->email, 'state' => 'inativo', '--yes' => true,
        ])->assertExitCode(1);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_cli_prevents_revocation_of_last_active_admin(): void
    {
        $admin = $this->admin();

        $this->artisan('repasse:access:revoke-admin', [
            'email' => $admin->email, '--yes' => true,
        ])->assertExitCode(1);

        $this->assertTrue($admin->fresh()->hasPermission('users.manage'));
    }

    public function test_cli_revocation_removes_admin_permission_and_revokes_old_jwt(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $token = $this->token($second);

        $this->artisan('repasse:access:revoke-admin', [
            'email' => $second->email, '--yes' => true,
        ])->assertExitCode(0);

        $this->assertFalse($second->fresh()->hasPermission('users.manage'));
        $this->withToken($token)->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $second->id, 'event_type' => 'platform_admin_revoked',
        ]);
        $this->assertTrue($first->fresh()->hasPermission('users.manage'));
    }
}
