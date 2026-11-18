<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ExternalTransferReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('external-report-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(array $grants = ['cases.manage', 'scenarios.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create([
            'slug' => 'ext_report_'.$user->id, 'name' => 'Operador externo',
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

    private function fixture(string $jwt): array
    {
        $case = $this->withToken($jwt)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo com repasse externo',
            'purchase_amount_cents' => 2500000, 'purchased_on' => '2026-11-01',
        ])->assertCreated()->json('data.id');
        $origin = Party::query()->create(['type' => 'company', 'name' => 'Responsável por repasse']);
        $first = Party::query()->create(['type' => 'person', 'name' => 'Favorecido Um']);
        $second = Party::query()->create(['type' => 'person', 'name' => 'Favorecido Dois']);
        foreach ([$origin, $first, $second] as $party) {
            $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$case}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        $template = $this->withToken($jwt)->postJson(
            '/api/v1/finance/distribution-templates', [
                'case_id' => $case,
                'name' => 'Distribuição hipotética QA',
                'description' => null,
                'source_party_id' => $origin->id,
                'lines' => [
                    ['party_id' => $first->id, 'basis_points' => 6000, 'memo' => null],
                    ['party_id' => $second->id, 'basis_points' => 4000, 'memo' => null],
                ],
            ]
        )->assertCreated()->json('data.id');
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, [
            'title' => 'Proposta para prestação externa QA',
            'description' => null,
            'template_id' => $template, 'template_revision' => 1,
            'reference_cents' => 10001,
            'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), null),
            'evidence_ids' => [],
        ])->assertCreated()->json('data.id');

        $preparation = "{$terms}/{$term}/preparation";
        $sha = $this->withToken($jwt)->getJson($preparation)->assertOk()
            ->json('data.snapshot_sha256');
        $record = $this->withToken($jwt)->postJson("{$preparation}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $sha, 'adjustments' => [],
        ])->assertCreated()->json('data');
        $proposal = $this->withToken($jwt)->postJson("{$preparation}/proposals", [
            'request_key' => (string) Str::uuid(),
            'preparation_record_id' => $record['id'],
            'expected_record_sha256' => $record['snapshot_sha256'],
            'payer_party_id' => $origin->id,
        ])->assertCreated()->json('data');
        $base = "{$preparation}/proposals/{$proposal['id']}/external-transfers";

        return [$case, $term, $proposal, $base, $first, $second, $origin];
    }

    private function sale(string $jwt, int $case): void
    {
        $endpoint = "/api/v1/repasse-cases/{$case}";
        $sha = $this->withToken($jwt)->getJson("{$endpoint}/closeout-review")
            ->assertOk()->json('data.review_sha256');
        $this->withToken($jwt)->postJson("{$endpoint}/sale/complete", [
            'sale_amount_cents' => 4100000, 'sold_on' => '2026-11-17',
            'buyer_name' => 'Comprador QA',
            'expected_closeout_sha256' => $sha,
            'acknowledge_pending' => false,
        ])->assertOk()->assertJsonPath('data.status', 'archived');
    }

    private function input(array $proposal, Party $beneficiary, array $override = []): array
    {
        return array_replace([
            'request_key' => (string) Str::uuid(),
            'expected_proposal_sha256' => $proposal['snapshot_sha256'],
            'beneficiary_party_id' => $beneficiary->id,
            'amount_cents' => 1500,
            'transferred_on' => '2026-11-17',
            'external_reference' => 'EXT-001',
            'operator_note' => 'Declaração de operador; não verificada pelo banco',
            'confirmed_external' => '1',
            'proof' => UploadedFile::fake()->create('comprovante.pdf', 120, 'application/pdf'),
        ], $override);
    }

    private function create(string $jwt, string $base, array $input): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($jwt)->withHeader('Accept', 'application/json')
            ->post($base, $input);
    }

    public function test_report_after_closed_sale_is_private_idempotent_and_never_executes_payment(): void
    {
        $jwt = $this->token();
        [$case, , $proposal, $base, $beneficiary] = $this->fixture($jwt);
        $input = $this->input($proposal, $beneficiary);
        // Nenhuma declaração de repasse antes de venda real e datada.
        $this->create($jwt, $base, $input)->assertUnprocessable()
            ->assertJsonValidationErrors('case');
        $this->sale($jwt, $case);

        $response = $this->create($jwt, $base, $input)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.status', 'reported')
            ->assertJsonPath('data.amount_cents', 1500)
            ->assertJsonPath('data.proposed_reference_cents', 6001)
            ->assertJsonPath('data.bank_confirmed', false)
            ->assertJsonPath('data.payment_executed', false)
            ->assertJsonPath('data.obligation_created', false);
        $id = $response->json('data.id');
        $this->create($jwt, $base, $input)->assertOk()
            ->assertJsonPath('created', false)->assertJsonPath('data.id', $id);
        $this->create($jwt, $base, [
            ...$input, 'amount_cents' => 1600,
        ])->assertConflict();

        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.total_reported_cents', 1500)
            ->assertJsonPath('data.summary.reported_count', 1)
            ->assertJsonPath('data.summary.voided_count', 0)
            ->assertJsonPath('data.summary.lines.0.reference_cents', 6001)
            ->assertJsonPath('data.summary.lines.0.difference_to_reference_cents', 4501)
            ->assertJsonPath('data.summary.bank_confirmed', false)
            ->assertJsonPath('data.reports.total', 1)
            ->assertJsonPath('data.reports.data.0.id', $id);

        $row = DB::table('repasse_external_transfer_reports')->find($id);
        $this->assertNotNull($row);
        Storage::disk('local')->assertExists($row->proof_path);
        $this->get("{$base}/{$id}/proof", ['Accept' => 'application/json'])->assertUnauthorized();
        $this->withToken($jwt)->get("{$base}/{$id}/proof")
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $csv = $this->withToken($jwt)->get("{$base}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('1500', $csv);
        $this->assertStringContainsString('NÃO VERIFICADO PELO BANCO', $csv);
        $this->assertDatabaseCount('repasse_case_financial_entries', 0);
        $this->assertDatabaseCount('repasse_external_transfer_reports', 1);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $case, 'event_type' => 'external_transfer_reported',
        ]);
    }

    public function test_void_is_justified_keeps_original_proof_and_can_be_retried(): void
    {
        $jwt = $this->token();
        [$case, , $proposal, $base, $beneficiary] = $this->fixture($jwt);
        $this->sale($jwt, $case);
        $id = $this->create($jwt, $base, $this->input($proposal, $beneficiary))
            ->assertCreated()->json('data.id');
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Curto',
        ])->assertOk()->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.void_reason', 'Curto');
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Curto',
        ])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->withToken($jwt)->postJson("{$base}/{$id}/void", [
            'reason' => 'Outra justificativa',
        ])->assertConflict();
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.total_reported_cents', 0)
            ->assertJsonPath('data.summary.reported_count', 0)
            ->assertJsonPath('data.summary.voided_count', 1);
        $this->withToken($jwt)->getJson("{$base}?status=voided")->assertOk()
            ->assertJsonPath('data.reports.total', 1);
        $this->withToken($jwt)->getJson("{$base}?status=reported")->assertOk()
            ->assertJsonPath('data.reports.total', 0);
        $this->withToken($jwt)->get("{$base}/{$id}/proof")->assertOk();
        $this->assertSame(1, DB::table('repasse_case_events')
            ->where('repasse_case_id', $case)->where('event_type', 'external_transfer_voided')
            ->count());
        $this->assertDatabaseCount('repasse_external_transfer_reports', 1);
    }

    public function test_scope_permissions_and_invalid_payloads_block_external_reports(): void
    {
        $jwt = $this->token();
        [$case, , $proposal, $base, $beneficiary] = $this->fixture($jwt);
        [$otherCase, , , , $otherBeneficiary] = $this->fixture($jwt);
        $this->sale($jwt, $case);
        $data = $this->input($proposal, $beneficiary);

        $this->getJson($base)->assertUnauthorized();
        $this->post($base, $data, ['Accept' => 'application/json'])->assertUnauthorized();
        foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
            $weak = $this->token($grants);
            $this->withToken($weak)->getJson($base)->assertForbidden();
            $this->create($weak, $base, $data)->assertForbidden();
        }
        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$otherCase}/commercial-terms/{$proposal['term_id']}/preparation/proposals/{$proposal['id']}/external-transfers"
        )->assertNotFound();
        $this->withToken($jwt)->get(
            "/api/v1/repasse-cases/{$otherCase}/commercial-terms/{$proposal['term_id']}/preparation/proposals/{$proposal['id']}/external-transfers/export"
        )->assertNotFound();
        $this->create($jwt, $base, [
            ...$data, 'beneficiary_party_id' => $otherBeneficiary->id,
        ])->assertUnprocessable();
        $this->create($jwt, $base, [
            ...$data, 'transferred_on' => '2099-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('transferred_on');
        $this->create($jwt, $base, [
            ...$data, 'confirmed_external' => '0',
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed_external');
        $this->create($jwt, $base, [
            ...$data, 'payment_enabled' => '1',
        ])->assertUnprocessable();
        $this->create($jwt, $base, [
            ...$data, 'proof' => null,
        ])->assertUnprocessable();
        $this->create($jwt, $base, [
            ...$data, 'amount_cents' => 0,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('repasse_external_transfer_reports', 0);
    }

    public function test_tampered_private_proof_and_proposal_are_rejected_without_public_link(): void
    {
        $jwt = $this->token();
        [$case, , $proposal, $base, $beneficiary] = $this->fixture($jwt);
        $this->sale($jwt, $case);
        $id = $this->create($jwt, $base, $this->input($proposal, $beneficiary))
            ->assertCreated()->json('data.id');
        $row = DB::table('repasse_external_transfer_reports')->find($id);
        Storage::disk('local')->put($row->proof_path, 'arquivo adulterado');
        $this->withToken($jwt)->get("{$base}/{$id}/proof")->assertConflict();
        $this->withToken($jwt)->getJson("{$base}/{$id}")->assertOk()
            ->assertJsonPath('data.bank_confirmed', false);
        DB::table('repasse_obligation_proposals')->where('id', $proposal['id'])
            ->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->withToken($jwt)->getJson("{$base}/{$id}")->assertConflict();
        $this->withToken($jwt)->get("{$base}/export")->assertConflict();
    }

    public function test_summary_counts_all_records_before_pagination_and_csv_sanitizes_formulas(): void
    {
        $jwt = $this->token();
        [$case, , $proposal, $base, $beneficiary] = $this->fixture($jwt);
        $this->sale($jwt, $case);
        for ($i = 0; $i < 17; $i++) {
            $this->create($jwt, $base, $this->input($proposal, $beneficiary, [
                'external_reference' => $i === 0 ? '=SUM(1+1)' : 'REF-'.$i,
            ]))->assertCreated();
        }
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('data.summary.reported_count', 17)
            ->assertJsonPath('data.summary.total_reported_cents', 25500)
            ->assertJsonPath('data.reports.total', 17)
            ->assertJsonPath('data.reports.last_page', 2)
            ->assertJsonCount(15, 'data.reports.data');
        $this->withToken($jwt)->getJson("{$base}?page=2")->assertOk()
            ->assertJsonCount(2, 'data.reports.data');
        $csv = $this->withToken($jwt)->get("{$base}/export?status=all")
            ->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1+1)", $csv);
        $this->assertStringContainsString('INFORMADO PELO OPERADOR', $csv);
        $this->assertDatabaseCount('repasse_external_transfer_reports', 17);
    }
}
