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

    public function test_consolidacao_separa_declaracoes_anteriores_do_saldo_aprovado_e_nao_duplica_resultado(): void
    {
        [$tenant, $admin, $vehicle, $parties] = $this->tenant('macro53-resumo');
        $token = auth('api')->login($admin);
        $base = "/api/v1/repasse-cases/{$vehicle}";
        $url = "{$base}/settlements";
        $this->withToken($token);
        DB::table('repasse_cases')->where('id', $vehicle)
            ->update(['purchase_amount_cents' => 150000]);
        $draft = $this->postJson($url, $this->draft($parties))->assertCreated()->json('data');
        $this->postJson($url.'/'.$draft['id'].'/approve', ['confirm' => true])->assertOk();

        $external = (int) DB::table('repasse_external_transfer_reports')->insertGetId([
            'tenant_id' => $tenant->id, 'repasse_case_id' => $vehicle,
            'term_id' => null, 'proposal_id' => null,
            'payer_party_id' => $parties[0], 'beneficiary_party_id' => $parties[1],
            'request_key' => (string) Str::uuid(), 'intent_sha256' => str_repeat('a', 64),
            'amount_cents' => 1500, 'transferred_on' => '2026-11-18',
            'proof_name' => 'externo.pdf', 'proof_path' => 'qa/externo.pdf',
            'proof_mime' => 'application/pdf', 'proof_size_bytes' => 123,
            'proof_sha256' => str_repeat('b', 64),
        ]);

        $this->getJson($url.'/overview')->assertOk()
            ->assertJsonPath('data.controlled.approved_cents', 10000)
            ->assertJsonPath('data.controlled.paid_cents', 0)
            ->assertJsonPath('data.controlled.remaining_cents', 10000)
            ->assertJsonPath('data.external_reports.direct_cents', 1500)
            ->assertJsonPath('data.flags.possible_overlap', true);

        $this->postJson($url.'/'.$draft['id'].'/payments', [
            'request_key' => (string) Str::uuid(),
            'line_id' => $draft['lines'][0]['id'], 'amount_cents' => 2000,
            'paid_on' => '2026-11-18', 'confirmed_external' => true,
        ])->assertCreated();

        $this->getJson($url.'/overview')->assertOk()
            ->assertJsonPath('data.controlled.approved_cents', 10000)
            ->assertJsonPath('data.controlled.paid_cents', 2000)
            ->assertJsonPath('data.controlled.remaining_cents', 8000)
            ->assertJsonPath('data.external_reports.direct_cents', 1500)
            ->assertJsonPath('data.flags.possible_overlap', true)
            ->assertJsonPath('data.flags.bank_confirmed', false)
            ->assertJsonPath('data.flags.automatic_reconciliation', false)
            ->assertJsonPath('data.flags.sale_result_includes_repasses', false);

        $this->getJson("{$base}/finance/summary")->assertOk()
            ->assertJsonPath('data.sale_result_cents', 50000);
        $review = $this->getJson("{$base}/closeout-review")->assertOk()
            ->assertJsonPath('data.repasse_remaining_cents', 8000)
            ->assertJsonPath('data.repasse_overlap_needs_review', true)->json('data');
        $this->postJson("{$base}/complete", [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('acknowledge_pending');
        $this->postJson("{$base}/complete", [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => true,
        ])->assertOk()->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('pending_acknowledged', true);
        $this->assertDatabaseHas('repasse_external_transfer_reports', [
            'id' => $external, 'amount_cents' => 1500, 'voided_at' => null,
        ]);
    }

    public function test_revisao_sha_invalida_apos_aprovacao_e_protege_venda_e_participantes(): void
    {
        [, $admin, $vehicle, $parties] = $this->tenant('macro53-concorrencia');
        $this->withToken(auth('api')->login($admin));
        $base = "/api/v1/repasse-cases/{$vehicle}";
        $url = "{$base}/settlements";
        $draft = $this->postJson($url, $this->draft($parties))->assertCreated()->json('data');
        $before = $this->getJson("{$base}/closeout-review")->assertOk()->json('data.review_sha256');

        $this->postJson($url.'/'.$draft['id'].'/approve', ['confirm' => true])->assertOk();

        $this->postJson("{$base}/complete", [
            'expected_closeout_sha256' => $before, 'acknowledge_pending' => true,
        ])->assertStatus(409);
        $this->patchJson($base, ['sale_amount_cents' => 210000])
            ->assertUnprocessable()->assertJsonValidationErrors('sale_amount_cents');
        $this->deleteJson("{$base}/parties/{$parties[1]}")
            ->assertUnprocessable()->assertJsonValidationErrors('party_id');

        $payment = $this->postJson($url.'/'.$draft['id'].'/payments', [
            'request_key' => (string) Str::uuid(),
            'line_id' => $draft['lines'][0]['id'], 'amount_cents' => 2000,
            'paid_on' => '2026-11-18', 'confirmed_external' => true,
        ])->assertCreated()->json('data.payments.0.id');
        $this->postJson($url.'/'.$draft['id'].'/cancel', [
            'reason' => 'Alteração de venda',
        ])->assertStatus(409);
        $this->postJson($url.'/'.$draft['id']."/payments/{$payment}/void", [
            'reason' => 'Retificação antes de corrigir',
        ])->assertOk();
        $this->postJson($url.'/'.$draft['id'].'/cancel', [
            'reason' => 'Correção previamente autorizada',
        ])->assertOk();
        $this->patchJson($base, ['sale_amount_cents' => 210000])->assertOk()
            ->assertJsonPath('data.sale_amount_cents', 210000);
        $this->deleteJson("{$base}/parties/{$parties[1]}")->assertOk();
    }

    public function test_resumo_permanece_isolado_por_empresa_mesmo_com_registros_controlados(): void
    {
        [, $adminA, $caseA, $partiesA] = $this->tenant('macro53-empresa-a');
        [, $adminB, $caseB] = $this->tenant('macro53-empresa-b');
        $this->withToken(auth('api')->login($adminA))
            ->postJson("/api/v1/repasse-cases/{$caseA}/settlements", $this->draft($partiesA))
            ->assertCreated();
        $this->withToken(auth('api')->login($adminB))
            ->getJson("/api/v1/repasse-cases/{$caseA}/settlements/overview")
            ->assertNotFound();
        $this->getJson("/api/v1/repasse-cases/{$caseB}/settlements/overview")
            ->assertOk()
            ->assertJsonPath('data.controlled.approved_cents', 0)
            ->assertJsonPath('data.controlled.draft_cents', 0);
    }

    public function test_operador_cancela_proprio_rascunho_mas_nao_obrigacao_aprovada_ou_estorno(): void
    {
        [, $admin, $vehicle, $parties, $roles] = $this->tenant('macro54-permissoes');
        $operator = User::create([
            'tenant_id' => $admin->tenant_id, 'name' => 'Operador de repasses',
            'email' => 'macro54-operador@example.invalid',
            'password' => 'Senha&Aleatoria12345',
        ]);
        $operator->roles()->sync([$roles['tenant_user']->id]);
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $operatorToken = auth('api')->login($operator);
        $adminToken = auth('api')->login($admin);

        $this->withToken($operatorToken);
        $ownDraft = $this->postJson($url, $this->draft($parties))
            ->assertCreated()->json('data.id');
        $this->postJson($url.'/'.$ownDraft.'/cancel', [
            'reason' => 'Corrigir destinatários',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $approved = $this->postJson($url, $this->draft($parties))
            ->assertCreated()->json('data');
        $id = $approved['id'];
        $this->withToken($adminToken)->postJson($url.'/'.$id.'/approve', [
            'confirm' => true,
        ])->assertOk();
        $this->withToken($operatorToken)->postJson($url.'/'.$id.'/cancel', [
            'reason' => 'Sem alçada para cancelar',
        ])->assertForbidden();

        $paymentId = $this->withToken($operatorToken)->postJson($url.'/'.$id.'/payments', [
            'request_key' => (string) Str::uuid(),
            'line_id' => $approved['lines'][0]['id'],
            'amount_cents' => 1000,
            'paid_on' => '2026-11-18',
            'confirmed_external' => true,
        ])->assertCreated()->json('data.payments.0.id');

        $this->postJson($url.'/'.$id.'/payments/'.$paymentId.'/void', [
            'reason' => 'Estorno sem autorização',
        ])->assertForbidden();
        $this->assertDatabaseHas('repasse_settlement_payments', [
            'id' => $paymentId, 'voided_at' => null,
        ]);

        $this->withToken($adminToken)->postJson(
            $url.'/'.$id.'/payments/'.$paymentId.'/void',
            ['reason' => 'Estorno autorizado e documentado'],
        )->assertOk()->assertJsonPath('data.paid_cents', 0);
        $this->postJson($url.'/'.$id.'/cancel', [
            'reason' => 'Cancelamento autorizado da obrigação',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_replay_do_repassse_pendente_preserva_contrato_e_nao_cria_novo_pagamento(): void
    {
        [, $admin, $vehicle, $parties] = $this->tenant('macro54-idempotencia');
        $this->withToken(auth('api')->login($admin));
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $payload = $this->draft($parties);
        $created = $this->postJson($url, $payload)->assertCreated()->json('data');
        $this->postJson($url.'/'.$created['id'].'/approve', ['confirm' => true])->assertOk();

        $repeat = $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('created', false)->json('data');
        $this->assertSame($created['id'], $repeat['id']);
        $this->assertSame('approved', $repeat['status']);
        $this->postJson($url, [...$payload, 'base_amount_cents' => 9999])
            ->assertStatus(409);
        $this->assertSame(1, DB::table('repasse_settlements')
            ->where('repasse_case_id', $vehicle)->count());
    }

    public function test_fechamento_detecta_estorno_e_nova_parcela_com_mesmo_total(): void
    {
        [, $admin, $vehicle, $parties] = $this->tenant('macro54-snapshot');
        $this->withToken(auth('api')->login($admin));
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $created = $this->postJson($url, $this->draft($parties))
            ->assertCreated()->json('data');
        $id = $created['id'];
        $line = $created['lines'][0]['id'];
        $this->postJson($url.'/'.$id.'/approve', ['confirm' => true])->assertOk();
        $first = $this->postJson($url.'/'.$id.'/payments', [
            'request_key' => (string) Str::uuid(), 'line_id' => $line,
            'amount_cents' => 2000, 'paid_on' => '2026-11-18',
            'confirmed_external' => true,
        ])->assertCreated()->json('data.payments.0.id');
        $before = $this->getJson('/api/v1/repasse-cases/'.$vehicle.'/closeout-review')
            ->assertOk()->json('data');

        $this->postJson($url.'/'.$id.'/payments/'.$first.'/void', [
            'reason' => 'Retificação do documento de origem',
        ])->assertOk();
        $this->postJson($url.'/'.$id.'/payments', [
            'request_key' => (string) Str::uuid(), 'line_id' => $line,
            'amount_cents' => 2000, 'paid_on' => '2026-11-18',
            'confirmed_external' => true,
        ])->assertCreated();
        $after = $this->getJson('/api/v1/repasse-cases/'.$vehicle.'/closeout-review')
            ->assertOk()->json('data');
        $this->assertSame($before['repasse_paid_cents'], $after['repasse_paid_cents']);
        $this->assertSame($before['repasse_remaining_cents'], $after['repasse_remaining_cents']);
        $this->assertGreaterThan($before['repasse_event_cursor'], $after['repasse_event_cursor']);
        $this->assertNotSame($before['review_sha256'], $after['review_sha256']);
    }

    public function test_pagamento_futuro_nao_pode_ser_declarado_como_ja_realizado(): void
    {
        [, $admin, $vehicle, $parties] = $this->tenant('macro54-data');
        $this->withToken(auth('api')->login($admin));
        $url = '/api/v1/repasse-cases/'.$vehicle.'/settlements';
        $created = $this->postJson($url, $this->draft($parties))
            ->assertCreated()->json('data');
        $this->postJson($url.'/'.$created['id'].'/approve', [
            'confirm' => true,
        ])->assertOk();

        $this->postJson($url.'/'.$created['id'].'/payments', [
            'request_key' => (string) Str::uuid(),
            'line_id' => $created['lines'][0]['id'],
            'amount_cents' => 1000,
            'paid_on' => now()->addDay()->toDateString(),
            'confirmed_external' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('paid_on');
    }

}
