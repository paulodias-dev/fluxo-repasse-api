<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\TenantRoles;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\SaasDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SaasTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdminUserSeeder::class);
    }

    private function actor(string $slug): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Empresa teste '.$slug, 'slug' => $slug, 'is_active' => true,
        ]);
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Administrador '.$slug,
            'email' => $slug.'@example.invalid',
            'password' => 'S3nha&T3ste12345!',
        ]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $user->roles()->sync([$roles['tenant_admin']->id]);

        return [$tenant, $user];
    }

    private function case(int $tenantId, string $name, int $userId): int
    {
        return (int) DB::table('repasse_cases')->insertGetId([
            'tenant_id' => $tenantId, 'title' => $name,
            'status' => 'draft', 'created_by' => $userId,
            'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_login_jwt_e_consultas_impedem_vazamento_entre_empresas(): void
    {
        [$tenantA, $adminA] = $this->actor('teste-alfa');
        [$tenantB, $adminB] = $this->actor('teste-beta');
        $caseA = $this->case($tenantA->id, 'Veículo Alfa', $adminA->id);
        $caseB = $this->case($tenantB->id, 'Veículo Beta', $adminB->id);
        $partyB = (int) DB::table('parties')->insertGetId([
            'tenant_id' => $tenantB->id, 'type' => 'person',
            'name' => 'Participante só Beta', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tokenA = auth('api')->login($adminA);
        $this->withToken($tokenA)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.tenant_id', $tenantA->id)
            ->assertJsonPath('data.tenant.slug', 'teste-alfa');
        $vehicles = $this->withToken($tokenA)->getJson('/api/v1/repasse-cases');
        $vehicles->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $caseA);
        $this->withToken($tokenA)->getJson('/api/v1/repasse-cases/'.$caseB)->assertNotFound();
        $this->withToken($tokenA)->getJson('/api/v1/parties/'.$partyB)->assertNotFound();

        // Body e header fornecidos pelo cliente NUNCA trocam o tenant do JWT.
        $this->withToken($tokenA)
            ->withHeader('X-Tenant-Id', (string) $tenantB->id)
            ->getJson('/api/v1/repasse-cases?tenant_id='.$tenantB->id)
            ->assertOk()->assertJsonPath('total', 1);

        $tokenB = auth('api')->login($adminB);
        $this->withToken($tokenB)->getJson('/api/v1/repasse-cases')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $caseB);
        $this->withToken($tokenB)->getJson('/api/v1/repasse-cases/'.$caseA)->assertNotFound();
    }

    public function test_escritas_participantes_sao_carimbadas_pelo_tenant_da_sessao(): void
    {
        [$tenantA, $adminA] = $this->actor('escrita-alfa');
        [$tenantB, $adminB] = $this->actor('escrita-beta');
        $tokenA = auth('api')->login($adminA);

        $response = $this->withToken($tokenA)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Exclusivo da Alfa',
            'tenant_id' => $tenantB->id,
        ]);
        // Campos extras são proibidos pelos FormRequests ou ignorados:
        // nenhuma hipótese pode gravar a pessoa no tenant informado pelo cliente.
        $this->assertContains($response->status(), [201, 422]);
        $this->assertDatabaseMissing('parties', [
            'tenant_id' => $tenantB->id, 'name' => 'Exclusivo da Alfa',
        ]);
        if ($response->status() === 201) {
            $this->assertDatabaseHas('parties', [
                'tenant_id' => $tenantA->id, 'name' => 'Exclusivo da Alfa',
            ]);
        }

        $this->withToken($tokenA)->postJson('/api/v1/parties', [
            'type' => 'person', 'name' => 'Pessoa Alfa',
        ])->assertCreated();
        $this->assertDatabaseHas('parties', [
            'tenant_id' => $tenantA->id, 'name' => 'Pessoa Alfa',
        ]);

        $tokenB = auth('api')->login($adminB);
        $this->withToken($tokenB)->getJson('/api/v1/parties')
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_empresa_desativada_nao_aceita_sessao(): void
    {
        [$tenant, $admin] = $this->actor('inativa');
        $token = auth('api')->login($admin);

        $tenant->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_cargos_e_permissoes_de_uma_empresa_nao_sao_editaveis_por_outra(): void
    {
        [$tenantA, $adminA] = $this->actor('roles-alfa');
        [$tenantB, $adminB] = $this->actor('roles-beta');
        $roleA = Role::query()->where('tenant_id', $tenantA->id)
            ->where('slug', 'tenant_admin')->firstOrFail();
        $roleB = Role::query()->where('tenant_id', $tenantB->id)
            ->where('slug', 'tenant_admin')->firstOrFail();
        $this->assertNotSame($roleA->id, $roleB->id);

        $tokenA = auth('api')->login($adminA);
        $this->withToken($tokenA)->getJson('/api/v1/admin/access')
            ->assertOk()->assertJsonMissing(['id' => $roleB->id]);

        $this->withToken($tokenA)->patchJson('/api/v1/admin/access/roles/'.$roleB->id, [
            'name' => 'Escalado maliciosamente',
            'permissions' => ['users.manage', 'roles.manage'],
        ])->assertNotFound();

        $this->withToken($tokenA)->postJson('/api/v1/admin/users', [
            'name' => 'Tentativa de escalação', 'email' => 'attempt@empresa-a.test',
            'password' => 'S3nha&T3ste12345!', 'password_confirmation' => 'S3nha&T3ste12345!',
            'role_ids' => [$roleB->id],
        ])->assertUnprocessable();

        $this->assertSame('Administrador', $roleB->fresh()->name);
        $this->assertDatabaseMissing('users', ['email' => 'attempt@empresa-a.test']);

        $tokenB = auth('api')->login($adminB);
        $this->withToken($tokenB)->getJson('/api/v1/admin/access')
            ->assertOk()->assertJsonMissing(['id' => $roleA->id]);
    }

    public function test_uma_empresa_nao_pode_ler_auditoria_de_usuarios_da_outra(): void
    {
        [, $adminA] = $this->actor('audit-alfa');
        [, $adminB] = $this->actor('audit-beta');
        $tokenA = auth('api')->login($adminA);
        $tokenB = auth('api')->login($adminB);

        $this->withToken($tokenA)->getJson('/api/v1/admin/users/'.$adminB->id.'/events')
            ->assertNotFound();
        $this->withToken($tokenB)->getJson('/api/v1/admin/users/'.$adminA->id.'/events')
            ->assertNotFound();
        $this->assertDatabaseHas('auth_security_events', [
            'tenant_id' => $adminA->tenant_id, 'user_id' => $adminA->id,
        ]);
    }

    public function test_seeder_cria_duas_empresas_distintas_com_cargos_e_modulos(): void
    {
        $this->seed(SaasDemoSeeder::class);

        $tenantA = Tenant::query()->where('slug', 'demo-alfa')->firstOrFail();
        $tenantB = Tenant::query()->where('slug', 'demo-beta')->firstOrFail();
        $this->assertNotSame($tenantA->id, $tenantB->id);

        foreach ([$tenantA, $tenantB] as $tenant) {
            $this->assertSame(2, User::query()->where('tenant_id', $tenant->id)->count());
            $this->assertSame(5, DB::table('parties')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(3, DB::table('repasse_cases')->where('tenant_id', $tenant->id)->count());
            $this->assertGreaterThanOrEqual(6,
                DB::table('repasse_case_financial_entries')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1,
                DB::table('repasse_scenarios')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1,
                DB::table('repasse_distribution_templates')->where('tenant_id', $tenant->id)->count());
            $this->assertSame(1,
                DB::table('repasse_commercial_terms')->where('tenant_id', $tenant->id)->count());
            $admin = User::query()->where('tenant_id', $tenant->id)
                ->where('email', 'like', 'admin.%')->firstOrFail();
            $operator = User::query()->where('tenant_id', $tenant->id)
                ->where('email', 'like', 'usuario.%')->firstOrFail();
            $this->assertTrue($admin->roles()->where('slug', 'tenant_admin')->exists());
            $this->assertTrue($operator->roles()->where('slug', 'tenant_user')->exists());
        }

        // Seeder reexecutável sem duplicar registros nem versões.
        $this->seed(SaasDemoSeeder::class);
        $this->assertSame(6, DB::table('repasse_cases')->count());
        $this->assertSame(2, DB::table('repasse_commercial_terms')->count());
    }
}
