<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CaseOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('case-operations-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $allowed = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(['slug' => 'case_operator'], ['name' => 'Operador']);
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function createCase(string $token): int
    {
        return $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Caso para operação',
        ])->assertCreated()->json('data.id');
    }

    public function test_all_new_endpoints_reject_unauthorized_sessions(): void
    {
        $this->getJson('/api/v1/operations/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/operations/tasks')->assertUnauthorized();
        $this->getJson('/api/v1/operations/tasks/1')->assertUnauthorized();
        $this->getJson('/api/v1/operations/export?entity=tasks')->assertUnauthorized();
        $this->postJson('/api/v1/repasse-cases/1/tasks', ['title' => 'Sem acesso'])->assertUnauthorized();
        $this->postJson('/api/v1/repasse-cases/1/notes', ['body' => 'Sem acesso'])->assertUnauthorized();
        $token = $this->token(false);
        $this->withToken($token)->getJson('/api/v1/operations/dashboard')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/repasse-cases/1/evidence')->assertForbidden();
    }

    public function test_tasks_checklist_and_notes_operate_end_to_end(): void
    {
        $token = $this->token();
        $case = $this->createCase($token);
        $base = "/api/v1/repasse-cases/{$case}";

        $task = $this->withToken($token)->postJson("$base/tasks", [
            'title' => 'Conferir documentos', 'priority' => 'high', 'due_on' => '2026-12-01',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->withToken($token)->patchJson("$base/tasks/$task", [
            'status' => 'done',
        ])->assertOk()->assertJsonPath('data.status', 'done');

        $item = $this->withToken($token)->postJson("$base/checklist", [
            'label' => 'Conferir identidade',
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson("$base/checklist/$item", [
            'checked' => true,
        ])->assertOk();

        $this->withToken($token)->postJson("$base/notes", [
            'body' => 'Dossiê revisado, ainda sem operação financeira.',
        ])->assertCreated();
        $this->withToken($token)->getJson("$base/tasks")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson("$base/checklist")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson("$base/notes")->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/v1/operations/dashboard')
            ->assertOk()->assertJsonPath('stats.tasks_done', 1)
            ->assertJsonPath('stats.checklist_done', 1);
        $this->withToken($token)->getJson('/api/v1/operations/tasks?status=done')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/v1/operations/tasks/'.$task)
            ->assertOk()->assertJsonPath('data.title', 'Conferir documentos');
        $this->assertDatabaseHas('repasse_case_events', ['repasse_case_id' => $case, 'event_type' => 'note_added']);
    }

    public function test_dashboard_period_filters_open_commitments(): void
    {
        $token = $this->token();
        $case = $this->createCase($token);
        $this->withToken($token)->postJson("/api/v1/repasse-cases/{$case}/tasks", [
            'title' => 'Compromisso do mês', 'due_on' => '2026-12-10',
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/operations/dashboard?from=2026-12-01&to=2026-12-31')
            ->assertOk()->assertJsonPath('stats.tasks_open', 1)
            ->assertJsonPath('stats.commitments_scheduled', 1)
            ->assertJsonPath('upcoming_tasks.0.title', 'Compromisso do mês');
        $this->withToken($token)->getJson('/api/v1/operations/dashboard?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonPath('stats.tasks_open', 0)
            ->assertJsonPath('stats.commitments_scheduled', 0)
            ->assertJsonCount(0, 'upcoming_tasks');
    }

    public function test_archived_case_blocks_every_write_but_allows_consultation(): void
    {
        $token = $this->token();
        $case = $this->createCase($token);
        $this->archiveVehicleForQa($token, "/api/v1/repasse-cases/{$case}");
        $base = "/api/v1/repasse-cases/{$case}";
        $this->withToken($token)->postJson("$base/tasks", ['title' => 'Tarefa proibida'])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/checklist", ['label' => 'Checklist proibido'])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/notes", ['body' => 'Nota proibida'])->assertUnprocessable();
        $this->withToken($token)->getJson("$base/notes")->assertOk();
        $this->assertDatabaseCount('repasse_case_tasks', 0);
    }

    public function test_financial_fields_and_unprivileged_assignment_are_rejected(): void
    {
        $token = $this->token();
        $case = $this->createCase($token);
        $base = "/api/v1/repasse-cases/{$case}";
        $this->withToken($token)->postJson("$base/tasks", [
            'title' => 'Operação indevida', 'amount' => 700, 'pix_key' => 'test',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'pix_key']);
        $unprivileged = User::factory()->create();
        $this->withToken($token)->postJson("$base/tasks", [
            'title' => 'Tarefa com destinatário sem acesso', 'assigned_to' => $unprivileged->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_private_upload_and_metadata_do_not_expose_storage_path(): void
    {
        Storage::fake('local');
        $token = $this->token();
        $case = $this->createCase($token);
        $base = "/api/v1/repasse-cases/{$case}/evidence";
        $result = $this->withToken($token)->post($base, [
            'document_type' => 'outros',
            'document_label' => 'Documento de exemplo',
            'file' => UploadedFile::fake()->create('evidencia.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonMissingPath('data.storage_path');
        $id = $result->json('data.id');
        $this->withToken($token)->getJson($base)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.storage_path');
        $this->withToken($token)->get("$base/$id/download")->assertOk();
        $this->withToken($token)->patchJson("$base/$id/archive", [])->assertOk();
        $this->withToken($token)->get("$base/$id/download")->assertNotFound();
        $this->assertDatabaseCount('repasse_case_evidence', 1);
    }

    public function test_csv_export_requires_permission_and_rejects_invalid_entity(): void
    {
        $token = $this->token();
        $case = $this->createCase($token);
        $this->withToken($token)->get('/api/v1/operations/export?entity=cases')
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->withToken($token)->getJson('/api/v1/operations/export?entity=payments')
            ->assertUnprocessable();
    }
}
