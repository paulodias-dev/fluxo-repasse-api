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
        $this->withToken($jwt)->patchJson("$base/$id/state", [
            'revision' => 2, 'action' => 'archive',
        ])->assertOk()->assertJsonPath('data.revision', 3)
            ->assertJsonPath('data.archived_at', now()->toDateTimeString());
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
}
