<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TenantIsolationSaaSTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('saas-isolation-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function platformToken(): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->firstOrCreate([
            'slug' => 'platform_admin',
        ], ['name' => 'Administrador da plataforma']);
        foreach (['cases.manage', 'parties.manage', 'users.manage', 'roles.manage'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function tenant(int $id): static
    {
        return $this->withHeader('X-Tenant-ID', (string) $id);
    }

    public function test_owner_can_switch_companies_and_not_access_other_company_data(): void
    {
        $token = $this->platformToken();
        $first = (int) DB::table('tenants')->where('slug', 'empresa-inicial')->value('id');
        $second = $this->withToken($token)->postJson('/api/v1/auth/tenants', [
            'name' => 'Segunda empresa QA',
        ])->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);

        $this->withToken($token)->getJson('/api/v1/auth/tenants')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->withoutHeader('X-Tenant-ID')
            ->getJson('/api/v1/repasse-cases')->assertStatus(409)
            ->assertJsonPath('code', 'TENANT_REQUIRED');
        $this->tenant($first)->withToken($token)
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant_id', $first);
        $caseA = $this->tenant($first)->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Frota da empresa A',
            'purchased_on' => '2026-11-01', 'purchase_amount_cents' => 2000000,
        ])->assertCreated()->json('data.id');
        $partyA = $this->tenant($first)->withToken($token)->postJson('/api/v1/parties', [
            'name' => 'Participante empresa A', 'type' => 'person',
        ])->assertCreated()->json('data.id');

        $this->tenant($second)->withToken($token)
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.tenant_id', $second);
        $this->tenant($second)->withToken($token)
            ->getJson('/api/v1/repasse-cases')->assertOk()->assertJsonPath('total', 0);
        $this->tenant($second)->withToken($token)
            ->getJson("/api/v1/repasse-cases/{$caseA}")->assertNotFound();
        $this->tenant($second)->withToken($token)
            ->getJson("/api/v1/repasse-cases/{$caseA}/finance/summary")->assertNotFound();
        $this->tenant($second)->withToken($token)
            ->getJson("/api/v1/parties/{$partyA}")->assertNotFound();
        $this->tenant($second)->withToken($token)
            ->getJson('/api/v1/parties')->assertOk()->assertJsonPath('total', 0);

        $caseB = $this->tenant($second)->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Frota da empresa B',
        ])->assertCreated()->json('data.id');
        $partyB = $this->tenant($second)->withToken($token)->postJson('/api/v1/parties', [
            'name' => 'Participante empresa B', 'type' => 'person',
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('repasse_cases', ['id' => $caseA, 'tenant_id' => $first]);
        $this->assertDatabaseHas('repasse_cases', ['id' => $caseB, 'tenant_id' => $second]);
        $this->assertDatabaseHas('parties', ['id' => $partyA, 'tenant_id' => $first]);
        $this->assertDatabaseHas('parties', ['id' => $partyB, 'tenant_id' => $second]);

        $this->tenant($first)->withToken($token)
            ->getJson("/api/v1/repasse-cases/{$caseB}")->assertNotFound();
        $this->tenant($first)->withToken($token)
            ->getJson("/api/v1/parties/{$partyB}")->assertNotFound();
        $this->tenant($first)->withToken($token)
            ->postJson("/api/v1/repasse-cases/{$caseA}/parties", [
                'party_id' => $partyB,
            ])->assertUnprocessable();
        $this->tenant($second)->withToken($token)
            ->postJson("/api/v1/repasse-cases/{$caseA}/external-transfers", [])
            ->assertNotFound();
    }

    public function test_user_is_member_only_of_company_that_created_account(): void
    {
        $token = $this->platformToken();
        $first = (int) DB::table('tenants')->value('id');
        $second = $this->withToken($token)->postJson('/api/v1/auth/tenants', [
            'name' => 'Empresa isolada QA',
        ])->assertCreated()->json('data.id');

        $userB = $this->tenant($second)->withToken($token)
            ->postJson('/api/v1/admin/users', [
                'name' => 'Pessoa da empresa B',
                'email' => 'empresa-b@example.test',
                'password' => 'Strong-Passphrase-123!',
                'role_ids' => [],
            ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $second, 'user_id' => $userB, 'is_active' => true,
        ]);
        $this->assertDatabaseMissing('tenant_users', [
            'tenant_id' => $first, 'user_id' => $userB,
        ]);
        $this->tenant($first)->withToken($token)
            ->getJson('/api/v1/admin/users')->assertOk()
            ->assertJsonMissing(['email' => 'empresa-b@example.test']);
        $this->tenant($first)->withToken($token)
            ->patchJson("/api/v1/admin/users/{$userB}", [
                'name' => 'Tentativa inválida',
            ])->assertNotFound();
    }

    public function test_foreign_company_header_and_inactive_memberships_are_denied(): void
    {
        $ownerToken = $this->platformToken();
        $first = (int) DB::table('tenants')->value('id');
        $second = $this->withToken($ownerToken)->postJson('/api/v1/auth/tenants', [
            'name' => 'Empresa não vinculada',
        ])->assertCreated()->json('data.id');

        $stranger = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        DB::table('tenant_users')->insert([
            'tenant_id' => $first, 'user_id' => $stranger->id,
            'membership_role' => 'member', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $jwt = $this->postJson('/api/v1/auth/login', [
            'email' => $stranger->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
        $this->tenant($second)->withToken($jwt)
            ->getJson('/api/v1/repasse-cases')->assertForbidden();
        DB::table('tenant_users')->where('tenant_id', $first)
            ->where('user_id', $stranger->id)->update(['is_active' => false]);
        $this->tenant($first)->withToken($jwt)
            ->getJson('/api/v1/repasse-cases')->assertForbidden();
        $this->withHeader('X-Tenant-ID', '0')->withToken($ownerToken)
            ->getJson('/api/v1/repasse-cases')->assertUnprocessable();
    }
}
