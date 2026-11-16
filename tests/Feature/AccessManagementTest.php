<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $keys): array
    {
        config()->set('jwt.secret', str_repeat('access-test-secret-', 4));
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'operator_'. $user->id, 'name' => 'Operador']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['key' => $key])->id);
        }
        $user->roles()->attach($role);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
        return [$user, $token];
    }

    public function test_access_management_requires_permission(): void
    {
        $this->getJson('/api/v1/admin/access')->assertUnauthorized();
        [, $token] = $this->operator(['users.manage']);
        $this->withToken($token)->getJson('/api/v1/admin/access')->assertForbidden();
    }

    public function test_role_create_and_user_assignment_revokes_old_token(): void
    {
        [$actor, $token] = $this->operator(['roles.manage', 'parties.manage']);
        $user = User::factory()->create();
        $role = $this->withToken($token)->postJson('/api/v1/admin/access/roles', [
            'slug' => 'cadastro', 'name' => 'Cadastros', 'permissions' => ['parties.manage'],
        ])->assertOk()->json('data.id');
        $this->withToken($token)->putJson("/api/v1/admin/access/users/{$user->id}/roles", [
            'role_ids' => [$role],
        ])->assertOk()->assertJsonCount(1, 'roles');
        $this->assertTrue($user->fresh()->hasPermission('parties.manage'));
        $this->assertSame(1, (int) $user->fresh()->auth_version);
        $this->assertDatabaseHas('auth_security_events', ['event_type' => 'account_roles_changed']);
    }

    public function test_cannot_escalate_or_modify_protected_accounts(): void
    {
        [$actor, $token] = $this->operator(['roles.manage']);
        Permission::query()->firstOrCreate(['key' => 'users.manage']);
        $this->withToken($token)->postJson('/api/v1/admin/access/roles', [
            'slug' => 'escalation', 'name' => 'Não permitido', 'permissions' => ['users.manage'],
        ])->assertUnprocessable();
        $adminRole = Role::query()->create(['slug' => 'platform_admin', 'name' => 'Técnico']);
        $target = User::factory()->create();
        $target->roles()->attach($adminRole);
        $this->withToken($token)->putJson("/api/v1/admin/access/users/{$target->id}/roles", [
            'role_ids' => [],
        ])->assertForbidden();
        $this->withToken($token)->putJson("/api/v1/admin/access/users/{$actor->id}/roles", [
            'role_ids' => [],
        ])->assertForbidden();
    }
}
