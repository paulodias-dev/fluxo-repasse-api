<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminUserRoleOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('macro25-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        config()->set('jwt.persistent_claims', ['av']);
    }

    /** @return array{0: User, 1: string} */
    private function operator(array $permissions): array
    {
        $actor = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'operator_'.$actor->id, 'name' => 'Operador de teste']);
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([
                Permission::query()->firstOrCreate(['key' => $key])->id,
            ]);
        }
        $actor->roles()->attach($role->id);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $actor->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        return [$actor, $token];
    }

    private function payload(string $email, array $roleIds): array
    {
        return [
            'name' => 'Operador cadastrado',
            'email' => $email,
            'password' => 'Another-Strong-Password-234!',
            'password_confirmation' => 'Another-Strong-Password-234!',
            'role_ids' => $roleIds,
        ];
    }

    public function test_creates_user_and_assigns_authorized_roles_in_the_same_transaction(): void
    {
        [$actor, $token] = $this->operator(['users.manage', 'roles.manage', 'cases.manage']);
        $role = Role::query()->create(['slug' => 'vehicle_operator', 'name' => 'Operador de veículos']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'cases.manage'])->id);

        $this->withToken($token)->postJson('/api/v1/admin/users', $this->payload(
            'vehicle-operator@example.test', [$role->id],
        ))->assertCreated()->assertJsonPath('data.email', 'vehicle-operator@example.test');

        $created = User::query()->where('email', 'vehicle-operator@example.test')->firstOrFail();
        $this->assertTrue($created->hasPermission('cases.manage'));
        $this->assertSame([$role->id], $created->roles()->pluck('roles.id')->all());
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $created->id, 'actor_user_id' => $actor->id, 'event_type' => 'account_created',
        ]);
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $created->id, 'actor_user_id' => $actor->id, 'event_type' => 'account_roles_changed',
        ]);
    }

    public function test_users_manager_without_roles_permission_cannot_assign_roles_at_creation(): void
    {
        [, $token] = $this->operator(['users.manage']);
        $role = Role::query()->create(['slug' => 'viewer', 'name' => 'Leitor']);

        $this->withToken($token)->postJson('/api/v1/admin/users', $this->payload(
            'no-roles@example.test', [$role->id],
        ))->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'no-roles@example.test']);
    }

    public function test_rejects_roles_above_actor_permissions_without_creating_user(): void
    {
        [, $token] = $this->operator(['users.manage', 'roles.manage']);
        $role = Role::query()->create(['slug' => 'financial_operator', 'name' => 'Financeiro']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'scenarios.manage'])->id);

        $this->withToken($token)->postJson('/api/v1/admin/users', $this->payload(
            'escalation@example.test', [$role->id],
        ))->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
    }

    public function test_protected_platform_admin_role_is_never_assigned_by_the_http_form(): void
    {
        [, $token] = $this->operator(['users.manage', 'roles.manage']);
        $role = Role::query()->create(['slug' => 'platform_admin', 'name' => 'Administrador protegido']);

        $this->withToken($token)->postJson('/api/v1/admin/users', $this->payload(
            'protected@example.test', [$role->id],
        ))->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->assertDatabaseMissing('users', ['email' => 'protected@example.test']);
    }

    public function test_profile_and_roles_edit_is_atomic_and_revokes_existing_token_once(): void
    {
        [$actor, $token] = $this->operator(['users.manage', 'roles.manage', 'cases.manage']);
        $role = Role::query()->create(['slug' => 'vehicle_staff', 'name' => 'Equipe de veículos']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'cases.manage'])->id);
        $target = User::factory()->create([
            'email' => 'before-edit@example.test', 'password' => 'Strong-Passphrase-123!',
        ]);
        $targetToken = $this->postJson('/api/v1/auth/login', [
            'email' => $target->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');

        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Equipe atualizada', 'email' => 'after-edit@example.test',
            'role_ids' => [$role->id],
        ])->assertOk()->assertJsonPath('data.email', 'after-edit@example.test');

        $this->assertSame(2, (int) $target->fresh()->auth_version);
        $this->assertTrue($target->fresh()->hasPermission('cases.manage'));
        $this->withToken($targetToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $target->id, 'actor_user_id' => $actor->id, 'event_type' => 'account_roles_changed',
        ]);
        $this->assertDatabaseHas('auth_security_events', [
            'user_id' => $target->id, 'actor_user_id' => $actor->id, 'event_type' => 'account_email_changed',
        ]);
    }

    public function test_rejected_role_selection_rolls_back_profile_changes(): void
    {
        [, $token] = $this->operator(['users.manage', 'roles.manage']);
        $target = User::factory()->create(['name' => 'Nome original', 'email' => 'unchanged@example.test']);
        $role = Role::query()->create(['slug' => 'restricted_finance', 'name' => 'Financeiro restrito']);
        $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => 'scenarios.manage'])->id);

        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Alteração indevida', 'role_ids' => [$role->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->assertSame('Nome original', $target->fresh()->name);
        $this->assertSame([], $target->roles()->pluck('roles.id')->all());
        $this->assertSame(1, (int) $target->fresh()->auth_version);
    }

    public function test_user_manager_cannot_smuggle_roles_through_profile_update(): void
    {
        [, $token] = $this->operator(['users.manage']);
        $target = User::factory()->create(['name' => 'Nome original']);

        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->id, [
            'name' => 'Alteração indevida', 'role_ids' => [],
        ])->assertForbidden();

        $this->assertSame('Nome original', $target->fresh()->name);
    }

    public function test_self_role_updates_are_forbidden_even_with_full_permission(): void
    {
        [$actor, $token] = $this->operator(['users.manage', 'roles.manage']);

        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$actor->id, [
            'role_ids' => [],
        ])->assertForbidden();

        $this->assertTrue($actor->fresh()->hasPermission('roles.manage'));
    }
}
