<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class CasePackagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('case-package-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(array $grants = ['cases.manage', 'scenarios.manage', 'packages.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'package_role_'.$user->id, 'name' => 'Emissor documental']);
        foreach ($grants as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function fixtures(string $jwt): array
    {
        $case = $this->withToken($jwt)->postJson('/api/v1/repasse-cases', [
            'title' => 'Dossiê de prestação de contas QA', 'description' => 'Somente conferência',
        ])->assertCreated()->json('data.id');
        $base = "/api/v1/repasse-cases/{$case}";

        $task = $this->withToken($jwt)->postJson("$base/tasks", [
            'title' => 'Conferir anexos enviados',
        ])->assertCreated()->json('data.id');
        $this->withToken($jwt)->postJson("$base/checklist", [
            'label' => 'Conferir documentação inicial',
        ])->assertCreated();
        $this->withToken($jwt)->postJson("$base/notes", [
            'body' => 'Notas existentes e preservadas no manifesto.',
        ])->assertCreated();

        $file = $this->withToken($jwt)->post("$base/evidence", [
            'file' => UploadedFile::fake()->create('comprovante.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $scenario = $this->withToken($jwt)->postJson('/api/v1/finance/scenarios', [
            'case_id' => $case, 'client_reference' => (string) Str::uuid(),
            'title' => 'Distribuição simulada para conferência',
            'description' => null, 'source_party_id' => null,
            'gross_amount_cents' => 12345, 'allocations' => [],
        ])->assertCreated()->json('data.id');
        return compact('case', 'task', 'file', 'scenario');
    }

    private function data(array $ids): array
    {
        return [
            'title' => 'Pacote congelado para análise',
            'note' => 'Fotografia do dossiê, sem pagamento.',
            'evidence_ids' => [$ids['file']],
            'scenario_ids' => [$ids['scenario']],
            'review_ids' => [],
        ];
    }

    public function test_read_needs_two_grants_and_write_needs_third_grant(): void
    {
        $this->getJson('/api/v1/repasse-cases/1/packages')->assertUnauthorized();
        $caseJwt = $this->token(['cases.manage']);
        $this->withToken($caseJwt)->getJson('/api/v1/repasse-cases/1/packages')
            ->assertForbidden();
        $reader = $this->token(['cases.manage', 'scenarios.manage']);
        $ids = $this->fixtures($reader);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $this->withToken($reader)->getJson($base)->assertOk();
        $this->withToken($reader)->postJson("$base/preview", $this->data($ids))->assertOk();
        $this->withToken($reader)->postJson($base, $this->data($ids))->assertForbidden();
        $this->assertDatabaseCount('repasse_case_packages', 0);
    }

    public function test_preview_is_read_only_and_create_is_idempotent(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $preview = $this->withToken($jwt)->postJson("$base/preview", $input)
            ->assertOk()->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.manifest.summary.tasks', 1)
            ->assertJsonPath('data.manifest.summary.evidence', 1)
            ->assertJsonPath('data.manifest.summary.scenarios', 1)
            ->assertJsonPath('data.manifest.financial_authorization', false);
        $this->assertDatabaseCount('repasse_case_packages', 0);
        $manifestHash = $preview->json('data.manifest_sha256');

        $payload = $input + [
            'request_key' => (string) Str::uuid(),
            'expected_manifest_sha256' => $manifestHash,
        ];
        $created = $this->withToken($jwt)->postJson($base, $payload)
            ->assertCreated()->assertJsonPath('created', true);
        $package = $created->json('data.id');
        $this->withToken($jwt)->postJson($base, $payload)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $package);
        $this->assertDatabaseCount('repasse_case_packages', 1);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $ids['case'], 'event_type' => 'case_package_created',
        ]);
        $changed = $payload;
        $changed['title'] = 'Outro conteúdo usando a mesma chave';
        $this->withToken($jwt)->postJson($base, $changed)->assertConflict();
        $this->assertDatabaseCount('repasse_case_packages', 1);
        $this->withToken($jwt)->getJson("$base/$package/integrity")
            ->assertOk()->assertJsonPath('data.manifest_integrity', true)
            ->assertJsonPath('data.evidence_integrity', true);
        $this->withToken($jwt)->getJson("$base/$package")->assertOk()
            ->assertJsonMissingPath('data.manifest.evidence.0.storage_path')
            ->assertJsonPath('data.manifest.scenarios.0.snapshot.gross_amount_cents', 12345);
    }

    public function test_new_task_after_preview_forces_reconfirmation(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $hash = $this->withToken($jwt)->postJson("$base/preview", $input)
            ->assertOk()->json('data.manifest_sha256');
        $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$ids['case']}/notes", [
            'body' => 'Uma nota foi adicionada após a prévia.',
        ])->assertCreated();
        $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertConflict();
        $this->assertDatabaseCount('repasse_case_packages', 0);
    }

    public function test_old_package_keeps_original_snapshot_and_compare_shows_new_records(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $preview = $this->withToken($jwt)->postJson("$base/preview", $input)->assertOk();
        $first = $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(),
            'expected_manifest_sha256' => $preview->json('data.manifest_sha256'),
        ])->assertCreated()->json('data.id');
        $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$ids['case']}/notes", [
            'body' => 'Nova informação documental após o primeiro pacote.',
        ])->assertCreated();
        $secondPreview = $this->withToken($jwt)->postJson("$base/preview", $input)->assertOk();
        $second = $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(),
            'expected_manifest_sha256' => $secondPreview->json('data.manifest_sha256'),
        ])->assertCreated()->json('data.id');
        $this->withToken($jwt)->getJson("$base/$first")
            ->assertOk()->assertJsonCount(1, 'data.manifest.notes');
        $this->withToken($jwt)->getJson("$base/$second")
            ->assertOk()->assertJsonCount(2, 'data.manifest.notes');
        $this->withToken($jwt)->getJson("$base/$first/compare?other_id=$second")
            ->assertOk()->assertJsonPath('data.same_manifest', false)
            ->assertJsonCount(1, 'data.changes.notes.added_ids');
    }

    public function test_modified_file_is_flagged_without_rewriting_package(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $hash = $this->withToken($jwt)->postJson("$base/preview", $input)
            ->assertOk()->json('data.manifest_sha256');
        $package = $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertCreated()->json('data.id');
        $path = DB::table('repasse_case_evidence')->where('id', $ids['file'])->value('storage_path');
        Storage::disk('local')->put($path, 'conteúdo modificado após emissão');
        $this->withToken($jwt)->getJson("$base/$package/integrity")
            ->assertOk()->assertJsonPath('data.manifest_integrity', true)
            ->assertJsonPath('data.evidence_integrity', false)
            ->assertJsonPath('data.available_for_download', false);
        if (class_exists(ZipArchive::class)) {
            $this->withToken($jwt)->get("$base/$package/download")->assertConflict();
        }
        $this->assertDatabaseCount('repasse_case_packages', 1);
    }

    public function test_archived_evidence_must_not_be_reexposed_by_an_old_package_zip(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $hash = $this->withToken($jwt)->postJson("$base/preview", $input)
            ->assertOk()->json('data.manifest_sha256');
        $package = $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertCreated()->json('data.id');
        $this->withToken($jwt)->patchJson(
            "/api/v1/repasse-cases/{$ids['case']}/evidence/{$ids['file']}/archive", []
        )->assertOk();
        $this->withToken($jwt)->getJson("$base/$package")->assertOk()
            ->assertJsonPath('data.manifest.evidence.0.name', 'comprovante.pdf');
        $this->withToken($jwt)->getJson("$base/$package/integrity")
            ->assertOk()->assertJsonPath('data.available_for_download', false)
            ->assertJsonPath('data.evidence_integrity', false);
        if (class_exists(ZipArchive::class)) {
            $this->withToken($jwt)->get("$base/$package/download")->assertConflict();
        }
    }

    public function test_archived_case_preserves_historical_zip_but_rejects_new_package(): void
    {
        $jwt = $this->token();
        $ids = $this->fixtures($jwt);
        $base = "/api/v1/repasse-cases/{$ids['case']}/packages";
        $input = $this->data($ids);
        $hash = $this->withToken($jwt)->postJson("$base/preview", $input)
            ->assertOk()->json('data.manifest_sha256');
        $id = $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertCreated()->json('data.id');
        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/{$ids['case']}");
        $this->withToken($jwt)->postJson($base, $input + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertUnprocessable();
        $this->withToken($jwt)->getJson("$base/$id")->assertOk()
            ->assertJsonPath('data.manifest.case.status', 'draft');
        if (class_exists(ZipArchive::class)) {
            $this->withToken($jwt)->get("$base/$id/download")
                ->assertOk()->assertHeader('Content-Type', 'application/zip');
        }
    }
}
