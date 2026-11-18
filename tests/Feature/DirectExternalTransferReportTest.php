<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DirectExternalTransferReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('direct-external-transfer-qa-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(array $grants = ['cases.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create([
            'slug' => 'direct_ext_'.$user->id, 'name' => 'Operador do veículo',
        ]);
        foreach ($grants as $grant) {
            $permission = Permission::query()->firstOrCreate(['key' => $grant]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $token, bool $sold = true): array
    {
        $data = [
            'title' => 'Veículo operacional sem minuta',
            'purchased_on' => '2026-11-01',
            'purchase_amount_cents' => 2000000,
        ];
        if ($sold) {
            $data['sold_on'] = '2026-11-16';
            $data['sale_amount_cents'] = 3000000;
        }
        $case = $this->withToken($token)->postJson('/api/v1/repasse-cases', $data)
            ->assertCreated()->json('data.id');
        $payer = Party::query()->create(['type' => 'company', 'name' => 'Pagador vinculado']);
        $beneficiary = Party::query()->create(['type' => 'person', 'name' => 'Destinatário vinculado']);
        foreach ([$payer, $beneficiary] as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$case}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        return [$case, $payer, $beneficiary];
    }

    private function input(Party $payer, Party $beneficiary, array $changes = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'payer_party_id' => $payer->id,
            'beneficiary_party_id' => $beneficiary->id,
            'amount_cents' => 150000,
            'transferred_on' => '2026-11-17',
            'external_reference' => '=SUM(1+1)',
            'operator_note' => 'Relato externo, não conferido pelo banco',
            'confirmed_external' => '1',
            'proof' => UploadedFile::fake()->create('recibo.pdf', 100, 'application/pdf'),
        ], $changes);
    }

    private function send(string $token, string $url, array $input): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->withHeader('Accept', 'application/json')
            ->post($url, $input);
    }

    public function test_direct_flow_records_after_vehicle_archival_without_draft_or_scenario(): void
    {
        $jwt = $this->token(['cases.manage']);
        [$case, $payer, $beneficiary] = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/{$case}/external-transfers";
        $this->withToken($jwt)->patchJson("/api/v1/repasse-cases/{$case}", [
            'status' => 'archived',
        ])->assertOk();

        $this->withToken($jwt)->getJson("{$url}/options")->assertOk()
            ->assertJsonPath('data.sale_registered', true)
            ->assertJsonPath('data.payment_executed', false)
            ->assertJsonCount(2, 'data.parties');
        $payload = $this->input($payer, $beneficiary);
        $record = $this->send($jwt, $url, $payload)->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.proposal_id', null)
            ->assertJsonPath('data.term_id', null)
            ->assertJsonPath('data.proposed_reference_cents', null)
            ->assertJsonPath('data.payer_party_name', 'Pagador vinculado')
            ->assertJsonPath('data.beneficiary_party_name', 'Destinatário vinculado')
            ->assertJsonPath('data.amount_cents', 150000)
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.bank_confirmed', false)
            ->assertJsonPath('data.payment_executed', false)
            ->assertJsonPath('data.obligation_created', false);
        $id = $record->json('data.id');
        $this->send($jwt, $url, $payload)->assertOk()
            ->assertJsonPath('created', false)->assertJsonPath('data.id', $id);
        $this->send($jwt, $url, [...$payload, 'amount_cents' => 150001])
            ->assertConflict();
        $list = $this->withToken($jwt)->getJson($url)->assertOk()
            ->assertJsonPath('data.summary.total_reported_cents', 150000)
            ->assertJsonPath('data.summary.reported_count', 1)
            ->assertJsonPath('data.summary.proposal_id', null)
            ->assertJsonPath('data.summary.hypothetical_reference_cents', null)
            ->assertJsonPath('data.summary.lines.0.reference_cents', null)
            ->assertJsonPath('data.summary.lines.0.reported_cents', 150000)
            ->assertJsonPath('data.reports.total', 1);
        $this->withToken($jwt)->get("{$url}/{$id}/proof")
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $csv = $this->withToken($jwt)->get("{$url}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1+1)", $csv);
        $this->assertStringContainsString('NÃO CONFERIDO PELO BANCO', $csv);
        $this->assertDatabaseCount('repasse_external_transfer_reports', 1);
        $this->assertDatabaseCount('repasse_case_financial_entries', 0);
        $this->assertDatabaseHas('repasse_cases', ['id' => $case, 'status' => 'archived']);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $case, 'event_type' => 'external_transfer_reported',
        ]);
    }

    public function test_void_preserves_proof_and_history_and_is_idempotent(): void
    {
        $jwt = $this->token();
        [$case, $payer, $beneficiary] = $this->vehicle($jwt);
        $base = "/api/v1/repasse-cases/{$case}/external-transfers";
        $id = $this->send($jwt, $base, $this->input($payer, $beneficiary))
            ->assertCreated()->json('data.id');
        $original = DB::table('repasse_external_transfer_reports')->find($id);
        Storage::disk('local')->assertExists($original->proof_path);
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Declaração registrada incorretamente',
        ])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Declaração registrada incorretamente',
        ])->assertOk();
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Motivo diferente',
        ])->assertConflict();
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.total_reported_cents', 0)
            ->assertJsonPath('data.summary.reported_count', 0)
            ->assertJsonPath('data.summary.voided_count', 1);
        $this->withToken($jwt)->getJson("{$base}?status=reported")->assertOk()
            ->assertJsonPath('data.reports.total', 0);
        $this->withToken($jwt)->getJson("{$base}?status=voided")->assertOk()
            ->assertJsonPath('data.reports.total', 1);
        $this->withToken($jwt)->get("{$base}/{$id}/proof")->assertOk();
        $this->assertDatabaseCount('repasse_external_transfer_reports', 1);
        $this->assertSame(1, DB::table('repasse_case_events')
            ->where('repasse_case_id', $case)
            ->where('event_type', 'external_transfer_voided')->count());
    }

    public function test_scope_permissions_and_validation_reject_cross_vehicle_and_unauthorized_payload(): void
    {
        $jwt = $this->token();
        [$case, $payer, $beneficiary] = $this->vehicle($jwt);
        [$other, $outsidePayer, $outsideBeneficiary] = $this->vehicle($jwt);
        [$unsold] = $this->vehicle($jwt, false);
        $base = "/api/v1/repasse-cases/{$case}/external-transfers";
        $payload = $this->input($payer, $beneficiary);
        $this->getJson($base)->assertUnauthorized();
        $this->withToken($this->token([]))->getJson($base)->assertForbidden();
        $this->withToken($this->token([]))->getJson("{$base}/options")->assertForbidden();
        $this->send($this->token([]), $base, $payload)->assertForbidden();
        $this->send($jwt, "/api/v1/repasse-cases/{$unsold}/external-transfers", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('case');
        $this->send($jwt, $base, [
            ...$payload, 'payer_party_id' => $outsidePayer->id,
        ])->assertUnprocessable();
        $this->send($jwt, $base, [
            ...$payload, 'beneficiary_party_id' => $outsideBeneficiary->id,
        ])->assertUnprocessable();
        $this->send($jwt, $base, [
            ...$payload, 'payment_enabled' => '1',
        ])->assertUnprocessable();
        $this->send($jwt, $base, [
            ...$payload, 'confirmed_external' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed_external');
        $this->send($jwt, $base, [
            ...$payload, 'amount_cents' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_cents');
        $this->send($jwt, $base, [
            ...$payload, 'transferred_on' => '2099-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->send($jwt, $base, [
            ...$payload, 'proof' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertDatabaseCount('repasse_external_transfer_reports', 0);
    }

    public function test_mixed_proposal_scope_cannot_be_read_or_manipulated_by_direct_routes(): void
    {
        $jwt = $this->token();
        [$case, $payer, $beneficiary] = $this->vehicle($jwt);
        [$other] = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/{$case}/external-transfers";
        $id = $this->send($jwt, $url, $this->input($payer, $beneficiary))
            ->assertCreated()->json('data.id');
        $this->withToken($jwt)->getJson("/api/v1/repasse-cases/{$other}/external-transfers/{$id}")
            ->assertNotFound();
        $this->withToken($jwt)->get("/api/v1/repasse-cases/{$other}/external-transfers/{$id}/proof")
            ->assertNotFound();
        // Um registro direto nunca pode ser lido como se viesse de uma proposta.
        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$case}/commercial-terms/1/preparation/proposals/1/external-transfers/{$id}"
        )->assertNotFound();
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/{$other}/external-transfers/{$id}/void",
            ['reason' => 'Tentativa fora do escopo']
        )->assertNotFound();
        $this->get("{$url}/{$id}/proof", ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_tampering_and_pagination_use_complete_dataset_not_current_page(): void
    {
        $jwt = $this->token();
        [$case, $payer, $beneficiary] = $this->vehicle($jwt);
        $base = "/api/v1/repasse-cases/{$case}/external-transfers";
        for ($i = 0; $i < 17; $i++) {
            $this->send($jwt, $base, $this->input($payer, $beneficiary, [
                'external_reference' => 'REF-'.$i,
            ]))->assertCreated();
        }
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.total_reported_cents', 2550000)
            ->assertJsonPath('data.summary.reported_count', 17)
            ->assertJsonPath('data.reports.total', 17)
            ->assertJsonPath('data.reports.last_page', 2)
            ->assertJsonCount(15, 'data.reports.data');
        $second = $this->withToken($jwt)->getJson("{$base}?page=2")->assertOk()
            ->assertJsonCount(2, 'data.reports.data');
        $id = $second->json('data.reports.data.0.id');
        DB::table('repasse_external_transfer_reports')->where('id', $id)
            ->update(['amount_cents' => 1]);
        $this->withToken($jwt)->getJson("{$base}/{$id}")->assertConflict();
        $this->withToken($jwt)->get("{$base}/export")->assertConflict();
        $this->withToken($jwt)->get("{$base}/{$id}/proof")->assertConflict();
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Tentativa sobre registro adulterado',
        ])->assertConflict();
    }
}
