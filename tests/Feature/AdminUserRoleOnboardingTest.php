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
}
