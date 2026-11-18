<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ControlledRepasseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdminUserSeeder::class);
    }

    private function tenant(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => $slug,
            'email' => $slug.'@example.invalid', 'password' => 'Senha&Aleatoria12345',
        ]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $admin->roles()->sync([$roles['tenant_admin']->id]);
        $vehicle = DB::table('repasse_cases')->insertGetId([
            'tenant_id' => $tenant->id, 'title' => 'Veículo '.$slug, 'status' => 'draft',
            'sale_amount_cents' => 200000, 'sold_on' => '2026-11-18',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $parties = [];
        foreach (['Pagador', 'Favorecido'] as $name) {
            $partyId = DB::table('parties')->insertGetId([
                'tenant_id' => $tenant->id, 'type' => 'person',
                'name' => $name.' '.$slug, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repasse_case_parties')->insert([
                'tenant_id' => $tenant->id, 'repasse_case_id' => $vehicle,
                'party_id' => $partyId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $parties[] = $partyId;
        }
        return [$tenant, $admin, $vehicle, $parties, $roles];
    }

    private function draft(array $parties, ?string $key = null): array
    {
        return [
            'request_key' => $key ?? (string) Str::uuid(),
            'payer_party_id' => $parties[0],
            'base_amount_cents' => 10000,
            'lines' => [
                ['beneficiary_party_id' => $parties[1], 'kind' => 'repasse',
                    'mode' => 'percentage', 'basis_points' => 9000],
                ['beneficiary_party_id' => $parties[0], 'kind' => 'commission',
                    'mode' => 'percentage', 'basis_points' => 1000],
            ],
        ];
    }

    public function test_criacao_idempotente_aprovacao_pelo_autor_parcial_estorno_e_cancelamento(): void
    {
        [$tenant, $admin, $vehicle, $parties] = $this->tenant('repasse-52');
        $this->withToken(auth('api')->login($admin));
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $payload = $this->draft($parties);
        $created = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.status', 'draft');
        $id = $created->json('data.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('created', false);
        $this->assertSame(1, DB::table('repasse_settlements')->where('tenant_id', $tenant->id)->count());
        $line = $created->json('data.lines.0.id');
        $this->postJson($url.'/'.$id.'/payments', [
            'request_key' => (string) Str::uuid(), 'line_id' => $line,
            'amount_cents' => 4000, 'paid_on' => '2026-11-18',
            'confirmed_external' => true,
        ])->assertStatus(409);
        $this->postJson($url.'/'.$id.'/approve', ['confirm' => true])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $paymentKey = (string) Str::uuid();
        $payment = [
            'request_key' => $paymentKey, 'line_id' => $line,
            'amount_cents' => 4000, 'paid_on' => '2026-11-18',
            'confirmed_external' => true,
        ];
        $response = $this->postJson($url.'/'.$id.'/payments', $payment)->assertCreated();
        $paymentId = $response->json('data.payments.0.id');
        $response->assertJsonPath('data.lines.0.remaining_cents', 5000);
        $this->postJson($url.'/'.$id.'/payments', $payment)
            ->assertOk()->assertJsonPath('created', false);
        $this->postJson($url.'/'.$id.'/payments', [
            ...$payment, 'request_key' => (string) Str::uuid(), 'amount_cents' => 5001,
        ])->assertUnprocessable();
        $this->postJson($url.'/'.$id.'/cancel', ['reason' => 'Revisão solicitada'])
            ->assertStatus(409);
        $this->postJson($url.'/'.$id.'/payments/'.$paymentId.'/void', [
            'reason' => 'Pagamento informado incorretamente',
        ])->assertOk()->assertJsonPath('data.remaining_cents', 10000);
        $this->postJson($url.'/'.$id.'/cancel', ['reason' => 'Cancelamento autorizado'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.remaining_cents', 0)
            ->assertJsonPath('data.lines.0.remaining_cents', 0);
        $this->assertSame(5, DB::table('repasse_settlement_events')
            ->where('tenant_id', $tenant->id)->count());
    }

    public function test_empresa_nao_acessa_ou_modifica_repasses_de_outro_tenant(): void
    {
        [, $adminA, $caseA, $partiesA] = $this->tenant('repasse-alfa');
        [, $adminB, $caseB, $partiesB] = $this->tenant('repasse-beta');
        $tokenA = auth('api')->login($adminA);
        $tokenB = auth('api')->login($adminB);
        $urlA = '/api/v1/repasse-cases/'.$caseA.'/settlements';
        $this->withToken($tokenA);
        $this->postJson($urlA, $this->draft($partiesB))->assertUnprocessable();
        $id = $this->postJson($urlA, $this->draft($partiesA))->assertCreated()->json('data.id');
        $this->withToken($tokenB)->getJson($urlA.'/'.$id)->assertNotFound();
        $this->withToken($tokenB)->postJson($urlA.'/'.$id.'/approve', ['confirm' => true])->assertNotFound();
        $this->withToken($tokenB)->getJson('/api/v1/repasse-cases/'.$caseB.'/settlements')
            ->assertOk()->assertJsonPath('total', 0);
    }

    public function test_papel_usuario_pode_cadastrar_mas_nao_aprovar_sem_grant(): void
    {
        [, $admin, $vehicle, $parties, $roles] = $this->tenant('repasse-role');
        $operator = User::create([
            'tenant_id' => $admin->tenant_id, 'name' => 'Operador',
            'email' => 'operador-repasse@example.invalid', 'password' => 'Senha&Aleatoria12345',
        ]);
        $operator->roles()->sync([$roles['tenant_user']->id]);
        $this->withToken(auth('api')->login($operator));
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $id = $this->postJson($url, $this->draft($parties))->assertCreated()->json('data.id');
        $this->postJson($url.'/'.$id.'/approve', ['confirm' => true])->assertForbidden();
    }
}
