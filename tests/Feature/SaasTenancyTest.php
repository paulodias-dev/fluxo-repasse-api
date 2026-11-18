<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TenantDatabase;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\FunctionalRolesSeeder;
use Database\Seeders\SaasDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SaasTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $createLegacyTestTenant = false;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('tenant-isolation-feature-test-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function tenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'slug' => $slug, 'name' => 'Empresa de teste '.$slug,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(int $tenantId, string $email): string
    {
        $role = Role::query()->firstOrCreate(['slug' => 'saas_operator'], ['name' => 'Operador de teste']);
        $permissions = [];
        foreach (['cases.manage', 'parties.manage'] as $key) {
            $permissions[] = Permission::query()->firstOrCreate(['key' => $key])->id;
        }
        $role->permissions()->syncWithoutDetaching($permissions);
        $user = User::query()->create([
            'tenant_id' => $tenantId, 'name' => $email, 'email' => $email,
            'password' => 'Strong-Passphrase-123!',
        ]);
        $user->roles()->sync([$role->id]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_all_business_tables_have_mandatory_tenant_column(): void
    {
        foreach (Schema::getTableListing() as $name) {
            if (str_starts_with($name, 'repasse_') || in_array($name, ['parties', 'party_events', 'party_links'], true)) {
                $this->assertTrue(Schema::hasColumn($name, 'tenant_id'), $name);
            }
        }
        $this->assertTrue(Schema::hasColumn('users', 'tenant_id'));
        $this->assertTrue(Schema::hasTable('tenants'));
    }

    public function test_tenants_cannot_list_read_or_mutate_each_others_vehicles_or_parties(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');
        $tokenA = $this->account($a, 'a@tenant-a.test');
        $tokenB = $this->account($b, 'b@tenant-b.test');

        $party = $this->withToken($tokenA)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Participante exclusivo A',
        ])->assertCreated()->json('data.id');

        $vehicle = $this->withToken($tokenA)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo exclusivo A',
        ])->assertCreated()->json('data.id');

        $this->withToken($tokenA)->postJson("/api/v1/repasse-cases/{$vehicle}/parties", [
            'party_id' => $party,
        ])->assertCreated();

        $this->withToken($tokenB)->getJson('/api/v1/repasse-cases')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($tokenB)->getJson('/api/v1/parties')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/repasse-cases/{$vehicle}")->assertNotFound();
        $this->withToken($tokenB)->getJson("/api/v1/parties/{$party}")->assertNotFound();
        $this->withToken($tokenB)->patchJson("/api/v1/repasse-cases/{$vehicle}", [
            'title' => 'Tentativa de adulteração',
        ])->assertNotFound();
        $this->withToken($tokenB)->postJson("/api/v1/repasse-cases/{$vehicle}/parties", [
            'party_id' => $party,
        ])->assertNotFound();

        $this->assertDatabaseHas('repasse_cases', [
            'id' => $vehicle, 'tenant_id' => $a, 'title' => 'Veículo exclusivo A',
        ]);
        $this->assertDatabaseHas('parties', ['id' => $party, 'tenant_id' => $a]);

        $this->withToken($tokenA)->getJson("/api/v1/repasse-cases/{$vehicle}")
            ->assertOk()->assertJsonPath('data.id', $vehicle);
    }

    public function test_cross_tenant_write_payload_and_request_header_cannot_override_tenant(): void
    {
        $a = $this->tenant('tenant-a');
        $b = $this->tenant('tenant-b');

        app(TenantContext::class)->run($a, function () use ($a, $b): void {
            $id = TenantDatabase::table('parties')->insertGetId([
                'type' => 'company', 'name' => 'Teste de escrita forçada',
                'tenant_id' => $b, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->assertDatabaseHas('parties', ['id' => $id, 'tenant_id' => $a]);
            TenantDatabase::table('parties')->where('id', $id)->update([
                'tenant_id' => $b, 'name' => 'Protegido',
            ]);
            $this->assertDatabaseHas('parties', ['id' => $id, 'tenant_id' => $a, 'name' => 'Protegido']);
        });
    }

    public function test_inactive_tenant_is_blocked_even_with_valid_jwt(): void
    {
        $a = $this->tenant('tenant-a');
        $token = $this->account($a, 'blocked@tenant-a.test');
        DB::table('tenants')->where('id', $a)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/repasse-cases')->assertForbidden();
    }

    public function test_real_functional_roles_and_demo_seeders_create_isolated_company_modules(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(FunctionalRolesSeeder::class);

        $admin = Role::query()->where('slug', 'tenant_admin')->firstOrFail();
        $operator = Role::query()->where('slug', 'tenant_user')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['users.manage', 'roles.manage', 'parties.manage', 'cases.manage', 'scenarios.manage',
                'scenario_reviews.manage', 'packages.manage'],
            $admin->permissions()->pluck('key')->all()
        );
        $this->assertNotContains('users.manage', $operator->permissions()->pluck('key')->all());
        $this->assertNotContains('roles.manage', $operator->permissions()->pluck('key')->all());

        $old = getenv('REPASSE_DEMO_PASSWORD');
        putenv('REPASSE_DEMO_PASSWORD=Safe-Demo-Secret-123!');
        try {
            $this->seed(SaasDemoSeeder::class);
            $this->assertSame(2, DB::table('tenants')->count());
            $this->assertSame(4, User::query()->count());
            foreach (DB::table('tenants')->get(['id']) as $tenant) {
                $id = (int) $tenant->id;
                $this->assertSame(3, DB::table('repasse_cases')->where('tenant_id', $id)->count());
                $this->assertSame(4, DB::table('parties')->where('tenant_id', $id)->count());
                $this->assertGreaterThanOrEqual(1, DB::table('repasse_scenarios')->where('tenant_id', $id)->count());
                $this->assertGreaterThanOrEqual(1, DB::table('repasse_distribution_templates')->where('tenant_id', $id)->count());
                $this->assertGreaterThanOrEqual(1, DB::table('repasse_commercial_terms')->where('tenant_id', $id)->count());
                $this->assertSame(3, DB::table('repasse_case_financial_entries')->where('tenant_id', $id)->count());
                $this->assertSame(1, User::query()->where('tenant_id', $id)->whereHas('roles',
                    fn ($q) => $q->where('slug', 'tenant_admin'))->count());
            }
        } finally {
            if ($old === false) {
                putenv('REPASSE_DEMO_PASSWORD');
            } else {
                putenv('REPASSE_DEMO_PASSWORD='.$old);
            }
        }
    }
}
