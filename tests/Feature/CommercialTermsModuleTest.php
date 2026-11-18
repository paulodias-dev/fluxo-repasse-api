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

final class CommercialTermsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('commercial-terms-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(array $grants = ['cases.manage', 'scenarios.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'terms_'.$user->id, 'name' => 'Operador de minutas']);
        foreach ($grants as $grant) {
            $permission = Permission::query()->firstOrCreate(['key' => $grant]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function fixture(string $token): array
    {
        $vehicle = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo de minuta comercial',
        ])->assertCreated()->json('data.id');
        $origin = Party::query()->create(['type' => 'company', 'name' => 'Origem QA']);
        $one = Party::query()->create(['type' => 'person', 'name' => 'Participante A']);
        $two = Party::query()->create(['type' => 'person', 'name' => 'Participante B']);
        foreach ([$origin, $one, $two] as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$vehicle}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        $model = [
            'case_id' => $vehicle, 'name' => 'Modelo percentual do veículo',
            'description' => null, 'source_party_id' => $origin->id,
            'lines' => [
                ['party_id' => $one->id, 'basis_points' => 6000, 'memo' => null],
                ['party_id' => $two->id, 'basis_points' => 4000, 'memo' => null],
            ],
        ];
        $template = $this->withToken($token)->postJson(
            '/api/v1/finance/distribution-templates', $model
        )->assertCreated()->json('data.id');

        return [$vehicle, $template, $model, $one, $two];
    }

    private function input(int $template, array $override = []): array
    {
        return array_replace([
            'title' => 'Minuta não executável QA',
            'description' => null,
            'template_id' => $template, 'template_revision' => 1,
            'reference_cents' => 10001,
            'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), null),
            'evidence_ids' => [],
        ], $override);
    }

    public function test_jwt_and_both_permissions_protect_the_complete_module(): void
    {
        $url = '/api/v1/repasse-cases/1/commercial-terms';
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, [])->assertUnauthorized();
        foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
            $jwt = $this->token($grants);
            $this->withToken($jwt)->getJson($url)->assertForbidden();
            $this->withToken($jwt)->postJson($url, [])->assertForbidden();
            $this->withToken($jwt)->getJson("$url/export")->assertForbidden();
        }
    }

    public function test_complete_draft_lifecycle_is_versioned_and_never_pays_anything(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $created = $this->withToken($jwt)->postJson($base, $this->input($template))
            ->assertCreated()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.financial_authorization', false)
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonPath('data.checks.filled', 0);
        $id = $created->json('data.id');

        $this->withToken($jwt)->getJson("$base/$id/preview")
            ->assertOk()->assertJsonPath('data.reference_cents', 10001)
            ->assertJsonPath('data.allocated_cents', 10001)
            ->assertJsonPath('data.difference_cents', 0)
            ->assertJsonPath('data.executable', false)
            ->assertJsonCount(2, 'data.allocations');

        $conditions = array_fill_keys(array_keys(CommercialTermsService::FIELDS), 'Descrição provisória declarada pelo operador');
        $data = $this->input($template, ['conditions' => $conditions]);
        $this->withToken($jwt)->putJson("$base/$id", ['revision' => 1, ...$data])
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.checks.filled', 10)
            ->assertJsonPath('data.checks.documentary_complete', true)
            ->assertJsonPath('data.payment_enabled', false);
        $this->withToken($jwt)->putJson("$base/$id", ['revision' => 1, ...$data])
            ->assertConflict();

        $this->withToken($jwt)->getJson("$base/$id/versions")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($jwt)->getJson("$base/$id/versions/1")
            ->assertOk()->assertJsonPath('data.snapshot.content.conditions.event_trigger', null)
            ->assertJsonPath('data.payment_enabled', false);
        $archived = $this->withToken($jwt)->patchJson("$base/$id/state", [
            'revision' => 2, 'action' => 'archive',
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertNotNull($archived->json('data.archived_at'));
        $this->withToken($jwt)->putJson("$base/$id", ['revision' => 3, ...$data])
            ->assertUnprocessable();
        $this->withToken($jwt)->patchJson("$base/$id/state", [
            'revision' => 3, 'action' => 'reopen',
        ])->assertOk()->assertJsonPath('data.revision', 4);

        $this->assertDatabaseCount('repasse_commercial_terms', 1);
        $this->assertDatabaseCount('repasse_commercial_term_versions', 4);
        $this->assertDatabaseCount('repasse_scenarios', 0);
    }

    public function test_model_revision_change_marks_existing_minute_outdated_without_changing_snapshot(): void
    {
        $jwt = $this->token();
        [$case, $template, $model] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $id = $this->withToken($jwt)->postJson($base, $this->input($template))
            ->assertCreated()->json('data.id');
        $model['revision'] = 1;
        unset($model['case_id']);
        $model['name'] = 'Modelo revisado pelo usuário';
        $this->withToken($jwt)->putJson(
            "/api/v1/finance/distribution-templates/$template", $model
        )->assertOk()->assertJsonPath('data.revision', 2);

        $this->withToken($jwt)->getJson("$base/$id")
            ->assertOk()->assertJsonPath('data.template_revision', 1)
            ->assertJsonPath('data.checks.model_changed_or_archived', true);
        $this->withToken($jwt)->putJson("$base/$id", [
            'revision' => 1, ...$this->input($template),
        ])->assertConflict();
        $this->withToken($jwt)->putJson("$base/$id", [
            'revision' => 1, ...$this->input($template, ['template_revision' => 2]),
        ])->assertOk()->assertJsonPath('data.template_revision', 2)
            ->assertJsonPath('data.checks.model_changed_or_archived', false);
    }

    public function test_cross_vehicle_models_and_evidence_are_rejected(): void
    {
        $jwt = $this->token();
        [$first, $template] = $this->fixture($jwt);
        [$second] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$second}/commercial-terms";
        $this->withToken($jwt)->postJson($base, $this->input($template))
            ->assertUnprocessable()->assertJsonValidationErrors('template_id');

        $file = $this->withToken($jwt)->post(
            "/api/v1/repasse-cases/{$first}/evidence",
            ['file' => UploadedFile::fake()->create('comercial.pdf', 30, 'application/pdf')],
            ['Accept' => 'application/json']
        )->assertCreated()->json('data.id');
        [$another, $anotherTemplate] = $this->fixture($jwt);
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/{$another}/commercial-terms",
            $this->input($anotherTemplate, ['evidence_ids' => [$file]])
        )->assertUnprocessable()->assertJsonValidationErrors('evidence_ids');
        $this->assertDatabaseCount('repasse_commercial_terms', 0);
    }

    public function test_explicit_evidence_snapshot_is_private_and_archived_file_is_flagged(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $file = $this->withToken($jwt)->post(
            "/api/v1/repasse-cases/{$case}/evidence",
            ['file' => UploadedFile::fake()->create('termo.pdf', 30, 'application/pdf')],
            ['Accept' => 'application/json']
        )->assertCreated()->json('data.id');
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $id = $this->withToken($jwt)->postJson($base, $this->input($template, [
            'evidence_ids' => [$file],
        ]))->assertCreated()
            ->assertJsonPath('data.evidence.0.id', $file)
            ->assertJsonMissingPath('data.evidence.0.storage_path')
            ->json('data.id');
        $this->withToken($jwt)->patchJson(
            "/api/v1/repasse-cases/{$case}/evidence/$file/archive", []
        )->assertOk();

        $this->withToken($jwt)->getJson("$base/$id")
            ->assertOk()->assertJsonPath('data.checks.archived_evidence.0', $file);
        $this->withToken($jwt)->getJson("$base/$id/versions/1")
            ->assertOk()->assertJsonPath('data.snapshot.content.evidence.0.id', $file);
        $this->assertDatabaseCount('repasse_commercial_term_evidence', 1);
    }

    public function test_unknown_fields_and_archived_vehicle_block_writes_and_preserve_history(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $this->withToken($jwt)->postJson($base, [
            ...$this->input($template), 'payment_instruction' => 'PIX fictício',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_instruction');
        $id = $this->withToken($jwt)->postJson($base, $this->input($template))
            ->assertCreated()->json('data.id');
        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/$case");
        $this->withToken($jwt)->getJson("$base/$id")->assertOk()
            ->assertJsonPath('data.checks.case_archived', true);
        $this->withToken($jwt)->postJson($base, $this->input($template))->assertUnprocessable();
        $this->withToken($jwt)->patchJson("$base/$id/state", [
            'revision' => 1, 'action' => 'archive',
        ])->assertUnprocessable();
    }

    public function test_csv_is_filtered_and_protects_spreadsheet_formula_injection(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $this->withToken($jwt)->postJson($base, $this->input($template, [
            'title' => '=HYPERLINK("https://bad.example")',
            'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), '=CMD'),
        ]))->assertCreated();
        $this->withToken($jwt)->postJson($base, $this->input($template, [
            'title' => 'Minuta sem expressão',
        ]))->assertCreated();

        $csv = $this->withToken($jwt)->get("$base/export?search=HYPERLINK")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'=CMD", $csv);
        $this->assertStringNotContainsString('Minuta sem expressão', $csv);
        $this->assertStringContainsString('NÃO AUTORIZA PAGAMENTO', $csv);
        $this->withToken($jwt)->getJson("$base?status=paid")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_copy_of_historical_revision_creates_independent_minute(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $id = $this->withToken($jwt)->postJson($base, $this->input($template))
            ->assertCreated()->json('data.id');
        $copy = $this->withToken($jwt)->postJson("$base/$id/fork", [
            'revision' => 1, 'title' => 'Nova proposta a partir da revisão 1',
        ])->assertCreated()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.payment_enabled', false)
            ->json('data.id');
        $this->assertNotSame($id, $copy);
        $this->assertDatabaseCount('repasse_commercial_terms', 2);
        $this->assertDatabaseCount('repasse_commercial_term_versions', 2);
    }
    public function test_preparation_consolidates_exact_documentary_review_realized_result_and_hypothetical_allocations(): void
    {
        $author = $this->token();
        [$case, $template, , $recipientA, $recipientB] = $this->fixture($author);
        $base = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $this->withToken($author)->patchJson("/api/v1/repasse-cases/{$case}", [
            'purchase_amount_cents' => 100000,
            'sale_amount_cents' => 150000,
            'sold_on' => '2026-11-16',
        ])->assertOk();
        $this->withToken($author)->postJson("/api/v1/repasse-cases/{$case}/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
            'description' => 'Despesa confirmada', 'amount_cents' => 11000,
        ])->assertCreated();
        $this->withToken($author)->postJson("/api/v1/repasse-cases/{$case}/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'Transporte',
            'description' => 'Despesa prevista', 'amount_cents' => 50000,
        ])->assertCreated();
        $this->withToken($author)->postJson("/api/v1/repasse-cases/{$case}/finance/entries", [
            'kind' => 'income', 'status' => 'realized', 'category' => 'Extras',
            'description' => 'Receita declarada', 'amount_cents' => 1000,
        ])->assertCreated();

        $created = $this->withToken($author)->postJson($base, $this->input($template))
            ->assertCreated();
        $term = $created->json('data.id');
        $route = "{$base}/{$term}/preparation";
        $result = $this->withToken($author)->getJson($route)
            ->assertOk()
            ->assertJsonPath('data.term_revision', 1)
            ->assertJsonPath('data.review_state', 'not_requested')
            ->assertJsonPath('data.documentary_preparation_complete', false)
            ->assertJsonPath('data.financial_policy_approved', false)
            ->assertJsonPath('data.executable', false)
            ->assertJsonPath('data.obligation_created', false)
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonPath('data.manual_reference_cents', 10001)
            ->assertJsonPath('data.allocated_cents', 10001)
            ->assertJsonPath('data.difference_cents', 0)
            ->assertJsonPath('data.sale_result_known_cents', 40000)
            ->assertJsonPath('data.allocations.0.party_id', $recipientA->id)
            ->assertJsonPath('data.allocations.0.amount_cents', 6001)
            ->assertJsonPath('data.allocations.1.party_id', $recipientB->id)
            ->assertJsonPath('data.allocations.1.amount_cents', 4000);
        $this->assertCount(11, $result->json('data.issues'));
        $this->assertSame(64, strlen($result->json('data.snapshot_sha256')));
        $hash = $result->json('data.snapshot_sha256');
        $this->withToken($author)->getJson($route)
            ->assertOk()->assertJsonPath('data.snapshot_sha256', $hash);

        // Parecer da revisão 1 não serve para revisão 2; a avaliação
        // documental exige a mesma revisão E o mesmo SHA atual.
        $conditions = array_fill_keys(array_keys(CommercialTermsService::FIELDS),
            'Condição preenchida para conferência documental interna.');
        $this->withToken($author)->putJson("{$base}/{$term}", [
            ...$this->input($template, ['conditions' => $conditions]), 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $review = $this->withToken($author)->postJson('/api/v1/commercial-reviews', [
            'case_id' => $case, 'term_id' => $term, 'term_revision' => 2,
            'request_key' => (string) Str::uuid(),
            'request_note' => 'Conferência independente das dez condições da minuta.',
        ])->assertCreated()->json('data.id');
        $reviewer = $this->token(['cases.manage', 'scenarios.manage', 'scenario_reviews.manage']);
        $this->withToken($reviewer)->postJson("/api/v1/commercial-reviews/{$review}/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Revisão documental verificada sem aprovação de pagamentos.',
        ])->assertOk();

        $ready = $this->withToken($author)->getJson($route)
            ->assertOk()
            ->assertJsonPath('data.review_state', 'reviewed')
            ->assertJsonPath('data.documentary_preparation_complete', true)
            ->assertJsonPath('data.issues', [])
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonPath('data.financial_authorization', false)
            ->assertJsonPath('data.sale_result_known_cents', 40000);
        $this->assertNotSame($hash, $ready->json('data.snapshot_sha256'));

        $csv = $this->withToken($author)->get("{$route}/export")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $out = $csv->streamedContent();
        $this->assertStringContainsString('snapshot_sha256', $out);
        $this->assertStringContainsString('NÃO', $out);
        $this->assertStringContainsString('6001', $out);
        $this->assertDatabaseCount('repasse_commercial_terms', 1);

        $this->withToken($author)->putJson("{$base}/{$term}", [
            ...$this->input($template, ['conditions' => $conditions,
                'title' => 'Minuta revisada novamente']), 'revision' => 2,
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->withToken($author)->getJson($route)->assertOk()
            ->assertJsonPath('data.review_state', 'not_requested')
            ->assertJsonPath('data.documentary_preparation_complete', false);
    }

    public function test_preparation_is_private_scoped_read_only_and_has_no_real_payment_side_effects(): void
    {
        $token = $this->token();
        [$case, $template] = $this->fixture($token);
        $other = $this->fixture($token);
        $term = $this->withToken($token)->postJson(
            "/api/v1/repasse-cases/{$case}/commercial-terms",
            $this->input($template, ['reference_cents' => null])
        )->assertCreated()->json('data.id');

        $path = "/api/v1/repasse-cases/{$case}/commercial-terms/{$term}/preparation";
        $this->withToken('invalid-jwt')->getJson($path)->assertUnauthorized();
        $this->withToken($this->token(['cases.manage']))->getJson($path)->assertForbidden();
        $this->withToken($this->token(['scenarios.manage']))->getJson($path)->assertForbidden();
        $this->withToken($token)->getJson(
            "/api/v1/repasse-cases/{$other[0]}/commercial-terms/{$term}/preparation"
        )->assertNotFound();
        $this->withToken($token)->getJson($path)
            ->assertOk()->assertJsonPath('data.manual_reference_cents', null)
            ->assertJsonPath('data.allocations', [])
            ->assertJsonPath('data.sale_result_known_cents', null)
            ->assertJsonPath('data.executable', false);
        $this->withToken($token)->get("{$path}/export")->assertOk();

        // Nenhuma mutação/obrigação criada durante a revisão.
        $this->assertDatabaseCount('repasse_commercial_term_versions', 1);
        $this->assertDatabaseCount('repasse_commercial_reviews', 0);
        $this->assertDatabaseCount('repasse_scenarios', 0);
    }


    public function test_manual_preparation_record_is_immutable_idempotent_and_allocates_net_cents(): void
    {
        $jwt = $this->token();
        [$case, $template, , $a, $b] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $base = "{$terms}/{$term}/preparation";
        $hash = $this->withToken($jwt)->getJson($base)->assertOk()->json('data.snapshot_sha256');

        $payload = [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $hash,
            'adjustments' => [
                ['kind' => 'addition', 'label' => 'Adição manual', 'amount_cents' => 1000],
                ['kind' => 'deduction', 'label' => 'Dedução declarada', 'amount_cents' => 100],
            ],
        ];
        $created = $this->withToken($jwt)->postJson("{$base}/records", $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.snapshot.reference_cents', 10001)
            ->assertJsonPath('data.snapshot.additions_cents', 1000)
            ->assertJsonPath('data.snapshot.deductions_cents', 100)
            ->assertJsonPath('data.snapshot.net_cents', 10901)
            ->assertJsonPath('data.snapshot.allocated_cents', 10901)
            ->assertJsonPath('data.snapshot.difference_cents', 0)
            ->assertJsonPath('data.snapshot.allocations.0.party_id', $a->id)
            ->assertJsonPath('data.snapshot.allocations.0.amount_cents', 6541)
            ->assertJsonPath('data.snapshot.allocations.1.party_id', $b->id)
            ->assertJsonPath('data.snapshot.allocations.1.amount_cents', 4360)
            ->assertJsonPath('data.snapshot.documentary_preparation_complete', false)
            ->assertJsonPath('data.financial_authorization', false)
            ->assertJsonPath('data.snapshot.obligation_created', false)
            ->assertJsonPath('data.snapshot.payment_enabled', false)
            ->assertJsonPath('data.snapshot.executable', false)
            ->assertJsonPath('data.stale', false);
        $id = $created->json('data.id');
        $sha = $created->json('data.snapshot_sha256');
        $this->assertSame(64, strlen($sha));

        $this->withToken($jwt)->postJson("{$base}/records", $payload)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $id);
        $this->withToken($jwt)->postJson("{$base}/records", [
            ...$payload, 'adjustments' => [],
        ])->assertConflict();
        $this->withToken($jwt)->getJson("{$base}/records")
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.payment_enabled', false);
        $this->withToken($jwt)->getJson("{$base}/records/{$id}")
            ->assertOk()->assertJsonPath('data.snapshot_sha256', $sha);
        $csv = $this->withToken($jwt)->get("{$base}/records/{$id}/export")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->streamedContent();
        $this->assertStringContainsString('Adição manual', $csv);
        $this->assertStringContainsString('10901', $csv);
        $this->assertStringContainsString('NÃO', $csv);
        $this->assertDatabaseCount('repasse_preparation_records', 1);
        $this->assertDatabaseCount('repasse_scenarios', 0);

        // Atualizar a minuta invalida o vínculo atual, mas NÃO altera passado.
        $this->withToken($jwt)->putJson("{$terms}/{$term}", [
            ...$this->input($template, ['title' => 'Revisão posterior']),
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($jwt)->getJson("{$base}/records/{$id}")
            ->assertOk()->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.snapshot_sha256', $sha)
            ->assertJsonPath('data.snapshot.net_cents', 10901);
        $this->withToken($jwt)->postJson("{$base}/records", [
            ...$payload, 'request_key' => (string) Str::uuid(),
        ])->assertConflict();
        // Retry exato permanece idempotente mesmo depois da mudança da fonte.
        $this->withToken($jwt)->postJson("{$base}/records", $payload)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.stale', true);
    }

    public function test_preparation_records_require_both_grants_and_never_cross_vehicle_scope(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        [$other] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $base = "{$terms}/{$term}/preparation/records";
        $payload = [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => str_repeat('a', 64),
            'adjustments' => [],
        ];
        $this->withToken('invalid-jwt')->getJson($base)->assertUnauthorized();
        foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
            $weak = $this->token($grants);
            $this->withToken($weak)->getJson($base)->assertForbidden();
            $this->withToken($weak)->postJson($base, $payload)->assertForbidden();
        }
        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$other}/commercial-terms/{$term}/preparation/records"
        )->assertNotFound();
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/{$other}/commercial-terms/{$term}/preparation/records",
            $payload
        )->assertNotFound();
        $source = $this->withToken($jwt)->getJson("{$terms}/{$term}/preparation")
            ->assertOk()->json('data.snapshot_sha256');
        $row = $this->withToken($jwt)->postJson($base, [
            ...$payload, 'expected_snapshot_sha256' => $source,
        ])->assertCreated()->json('data.id');
        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$other}/commercial-terms/{$term}/preparation/records/{$row}"
        )->assertNotFound();
        $this->withToken($jwt)->getJson("{$base}/99999")->assertNotFound();
        $this->withToken($jwt)->getJson("{$base}/{$row}")
            ->assertOk()->assertJsonPath('data.financial_authorization', false);
        $this->assertDatabaseCount('repasse_preparation_records', 1);
    }

    public function test_preparation_record_rejects_missing_reference_bad_adjustments_and_archived_writes(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson(
            $terms, $this->input($template, ['reference_cents' => null])
        )->assertCreated()->json('data.id');
        $base = "{$terms}/{$term}/preparation";
        $hash = $this->withToken($jwt)->getJson($base)->assertOk()->json('data.snapshot_sha256');
        $input = [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $hash, 'adjustments' => [],
        ];
        $this->withToken($jwt)->postJson("{$base}/records", $input)
            ->assertUnprocessable()->assertJsonValidationErrors('reference_cents');
        $this->withToken($jwt)->postJson("{$base}/records", [
            ...$input, 'request_key' => (string) Str::uuid(),
            'adjustments' => [['kind' => 'addition', 'label' => 'A', 'amount_cents' => 0]],
        ])->assertUnprocessable();
        $this->withToken($jwt)->postJson("{$base}/records", [
            ...$input, 'request_key' => (string) Str::uuid(), 'payment_enabled' => true,
        ])->assertUnprocessable();

        $this->withToken($jwt)->putJson("{$terms}/{$term}", [
            ...$this->input($template), 'revision' => 1,
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $hash2 = $this->withToken($jwt)->getJson($base)->assertOk()->json('data.snapshot_sha256');
        $this->withToken($jwt)->postJson("{$base}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $hash2,
            'adjustments' => [[
                'kind' => 'deduction', 'label' => 'Valor acima da referência',
                'amount_cents' => 20000,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('adjustments');

        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/{$case}");
        $newHash = $this->withToken($jwt)->getJson($base)->assertOk()
            ->json('data.snapshot_sha256');
        $this->withToken($jwt)->postJson("{$base}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $newHash, 'adjustments' => [],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('repasse_preparation_records', 0);
    }

    public function test_preparation_record_detects_tampering_and_paginates_complete_history(): void
    {
        $jwt = $this->token();
        [$case, $template] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $base = "{$terms}/{$term}/preparation";
        $hash = $this->withToken($jwt)->getJson($base)->assertOk()->json('data.snapshot_sha256');
        for ($i = 0; $i < 17; $i++) {
            $this->withToken($jwt)->postJson("{$base}/records", [
                'request_key' => (string) Str::uuid(),
                'expected_snapshot_sha256' => $hash,
                'adjustments' => [],
            ])->assertCreated();
        }
        $this->withToken($jwt)->getJson("{$base}/records")
            ->assertOk()->assertJsonPath('total', 17)->assertJsonPath('last_page', 2)
            ->assertJsonCount(15, 'data');
        $page = $this->withToken($jwt)->getJson("{$base}/records?page=2")
            ->assertOk()->assertJsonCount(2, 'data');
        $id = $page->json('data.0.id');
        DB::table('repasse_preparation_records')->where('id', $id)
            ->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->withToken($jwt)->getJson("{$base}/records/{$id}")->assertConflict();
        $this->withToken($jwt)->get("{$base}/records/{$id}/export")->assertConflict();
    }

    public function test_internal_proposal_is_immutable_idempotent_and_never_creates_a_debt(): void
    {
        $jwt = $this->token();
        [$case, $template, , $a, $b] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $preparation = "{$terms}/{$term}/preparation";
        $sha = $this->withToken($jwt)->getJson($preparation)
            ->assertOk()->json('data.snapshot_sha256');
        $record = $this->withToken($jwt)->postJson("{$preparation}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $sha,
            'adjustments' => [],
        ])->assertCreated()->json('data');
        $base = "{$preparation}/proposals";
        $this->withToken($jwt)->getJson("{$base}/options")
            ->assertOk()->assertJsonCount(3, 'data.parties')
            ->assertJsonPath('data.payment_enabled', false);
        $payload = [
            'request_key' => (string) Str::uuid(),
            'preparation_record_id' => $record['id'],
            'expected_record_sha256' => $record['snapshot_sha256'],
            'payer_party_id' => $a->id,
            'trigger_on' => '2026-11-17',
            'due_on' => '2026-11-20',
            'operator_note' => 'Análise documental interna',
        ];
        $created = $this->withToken($jwt)->postJson($base, $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.snapshot.net_cents', 10001)
            ->assertJsonPath('data.snapshot.lines.0.party_id', $a->id)
            ->assertJsonPath('data.snapshot.lines.0.proposed_amount_cents', 6001)
            ->assertJsonPath('data.snapshot.lines.1.party_id', $b->id)
            ->assertJsonPath('data.snapshot.lines.1.proposed_amount_cents', 4000)
            ->assertJsonPath('data.snapshot.lines.0.payable', false)
            ->assertJsonPath('data.snapshot.financial_policy_approved', false)
            ->assertJsonPath('data.snapshot.obligation_created', false)
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonPath('data.executable', false);
        $id = $created->json('data.id');
        $hash = $created->json('data.snapshot_sha256');

        $this->withToken($jwt)->postJson($base, $payload)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $id);
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'payer_party_id' => $b->id,
        ])->assertConflict();
        $this->withToken($jwt)->getJson($base)->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.payment_enabled', false);
        $this->withToken($jwt)->getJson("{$base}/{$id}")
            ->assertOk()->assertJsonPath('data.snapshot_sha256', $hash);

        $csv = $this->withToken($jwt)->get("{$base}/{$id}/export")
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Proposta não exigível', $csv);
        $this->assertStringContainsString('10001', $csv);
        $this->assertDatabaseCount('repasse_obligation_proposals', 1);
        $this->assertDatabaseCount('repasse_case_financial_entries', 0);

        $this->withToken($jwt)->putJson("{$terms}/{$term}", [
            ...$this->input($template, ['title' => 'Minuta alterada']),
            'revision' => 1,
        ])->assertOk();
        $this->withToken($jwt)->getJson("{$base}/{$id}")
            ->assertOk()->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.snapshot_sha256', $hash);
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'request_key' => (string) Str::uuid(),
        ])->assertConflict();
        $this->withToken($jwt)->postJson($base, $payload)
            ->assertOk()->assertJsonPath('created', false);
        DB::table('repasse_obligation_proposals')->where('id', $id)
            ->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->withToken($jwt)->getJson("{$base}/{$id}")->assertConflict();
        $this->withToken($jwt)->get("{$base}/{$id}/export")->assertConflict();
    }

    public function test_internal_proposals_reject_cross_vehicle_payers_unknown_fields_and_missing_permissions(): void
    {
        $jwt = $this->token();
        [$case, $template, , $a] = $this->fixture($jwt);
        [$other, , , $otherParty] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $preparation = "{$terms}/{$term}/preparation";
        $source = $this->withToken($jwt)->getJson($preparation)
            ->assertOk()->json('data.snapshot_sha256');
        $record = $this->withToken($jwt)->postJson("{$preparation}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $source,
            'adjustments' => [],
        ])->assertCreated()->json('data');
        $base = "{$preparation}/proposals";
        $payload = [
            'request_key' => (string) Str::uuid(),
            'preparation_record_id' => $record['id'],
            'expected_record_sha256' => $record['snapshot_sha256'],
            'payer_party_id' => $a->id,
        ];
        $this->getJson($base)->assertUnauthorized();
        foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
            $weak = $this->token($grants);
            $this->withToken($weak)->getJson($base)->assertForbidden();
            $this->withToken($weak)->postJson($base, $payload)->assertForbidden();
        }
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'payer_party_id' => $otherParty->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('payer_party_id');
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'payment_enabled' => true,
        ])->assertUnprocessable();
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'due_on' => '2026-11-10', 'trigger_on' => '2026-11-17',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_on');
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/{$other}/commercial-terms/{$term}/preparation/proposals",
            $payload
        )->assertNotFound();
        $this->withToken($jwt)->postJson($base, $payload)->assertCreated();
        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/{$case}");
        $this->withToken($jwt)->postJson($base, [
            ...$payload, 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable();
    }


    public function test_internal_proposals_paginate_and_keep_history_after_archive(): void
    {
        $jwt = $this->token();
        [$case, $template, , $payer] = $this->fixture($jwt);
        $terms = "/api/v1/repasse-cases/{$case}/commercial-terms";
        $term = $this->withToken($jwt)->postJson($terms, $this->input($template))
            ->assertCreated()->json('data.id');
        $preparation = "{$terms}/{$term}/preparation";
        $hash = $this->withToken($jwt)->getJson($preparation)
            ->assertOk()->json('data.snapshot_sha256');
        $record = $this->withToken($jwt)->postJson("{$preparation}/records", [
            'request_key' => (string) Str::uuid(),
            'expected_snapshot_sha256' => $hash,
            'adjustments' => [],
        ])->assertCreated()->json('data');
        $base = "{$preparation}/proposals";

        for ($i = 0; $i < 17; $i++) {
            $this->withToken($jwt)->postJson($base, [
                'request_key' => (string) Str::uuid(),
                'preparation_record_id' => $record['id'],
                'expected_record_sha256' => $record['snapshot_sha256'],
                'payer_party_id' => $payer->id,
                'operator_note' => 'Versão hipotética interna '.$i,
            ])->assertCreated();
        }

        $this->withToken($jwt)->getJson($base)
            ->assertOk()->assertJsonPath('total', 17)
            ->assertJsonPath('last_page', 2)->assertJsonCount(15, 'data');
        $secondPage = $this->withToken($jwt)->getJson("{$base}?page=2")
            ->assertOk()->assertJsonCount(2, 'data');
        $id = $secondPage->json('data.0.id');

        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/{$case}");
        $this->withToken($jwt)->getJson("{$base}/{$id}")
            ->assertOk()->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.snapshot.obligation_created', false);
        $this->withToken($jwt)->get("{$base}/{$id}/export")
            ->assertOk();
        $this->assertDatabaseCount('repasse_obligation_proposals', 17);
    }

}
