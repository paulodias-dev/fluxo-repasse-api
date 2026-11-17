<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->withToken($jwt)->patchJson("/api/v1/repasse-cases/$case", [
            'status' => 'archived',
        ])->assertOk();
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

}
