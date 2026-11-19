<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ChecklistAgendaWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('checklist-agenda-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(?int $tenant = null, bool $permission = true): string
    {
        $user = User::factory()->create([
            'password' => 'Strong-Passphrase-123!',
            ...($tenant ? ['tenant_id' => $tenant] : []),
        ]);
        if ($permission) {
            $role = Role::query()->firstOrCreate(
                ['slug' => 'qa_checklist_operator'], ['name' => 'Operador de QA']
            );
            $grant = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$grant->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $jwt): int
    {
        return $this->withToken($jwt)->postJson('/api/v1/repasse-cases', [
            'title' => 'Corolla XEi 2022',
        ])->assertCreated()->json('data.id');
    }

    private function checklistPayload(string $status = 'active'): array
    {
        return [
            'title' => 'Preparação para venda', 'status' => $status,
            'items' => [
                ['label' => 'Conferir CRLV e restrições', 'group_name' => 'Documentação'],
                ['label' => 'Realizar vistoria estética', 'group_name' => 'Preparação',
                    'due_on' => '2026-11-25'],
                ['label' => 'Publicar anúncio', 'group_name' => 'Venda'],
            ],
        ];
    }

    public function test_checklist_wizard_persists_groups_and_progress_without_duplicate_records(): void
    {
        $jwt = $this->token();
        $case = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/$case/checklists";
        $set = $this->withToken($jwt)->postJson($url, $this->checklistPayload())
            ->assertCreated()->json('data.id');

        $this->withToken($jwt)->getJson($url)->assertOk()
            ->assertJsonPath('sets.data.0.id', $set)
            ->assertJsonPath('sets.data.0.items_count', 3)
            ->assertJsonPath('sets.data.0.completed_count', 0);
        $details = $this->withToken($jwt)->getJson("$url/$set")->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.1.group_name', 'Preparação');
        $item = $details->json('data.items.0.id');
        $this->withToken($jwt)->patchJson("$url/$set/items/$item", ['checked' => true])
            ->assertOk()->assertJsonPath('data.items.0.checked_at', fn ($v) => is_string($v));
        $this->withToken($jwt)->getJson($url)->assertOk()
            ->assertJsonPath('sets.data.0.completed_count', 1);

        $this->withToken($jwt)->postJson($url, [
            ...$this->checklistPayload(), 'items' => [
                ['label' => 'Item sem grupo', 'group_name' => 'Inválido'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.group_name');
        $this->assertDatabaseCount('repasse_case_checklist_sets', 1);
        $this->assertDatabaseCount('repasse_case_checklist', 3);
    }

    public function test_draft_can_be_revised_but_active_checklist_structure_cannot_be_replaced(): void
    {
        $jwt = $this->token();
        $case = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/$case/checklists";
        $draft = $this->withToken($jwt)->postJson($url, $this->checklistPayload('draft'))
            ->assertCreated()->json('data.id');
        $this->withToken($jwt)->putJson("$url/$draft", [
            'title' => 'Vistoria da compra', 'status' => 'active',
            'items' => [['label' => 'Testar pneus', 'group_name' => 'Preparação']],
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->withToken($jwt)->getJson("$url/$draft")->assertOk()
            ->assertJsonPath('data.items.0.label', 'Testar pneus')
            ->assertJsonCount(1, 'data.items');
        $this->withToken($jwt)->putJson("$url/$draft", $this->checklistPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_calendar_and_private_attachments_are_vehicle_scoped(): void
    {
        $jwt = $this->token();
        $case = $this->vehicle($jwt);
        $otherCase = $this->vehicle($jwt);
        $path = '/api/v1/operations/calendar';
        $id = $this->withToken($jwt)->postJson($path, [
            'repasse_case_id' => $case, 'title' => 'Vistoria cautelar',
            'kind' => 'appointment', 'due_on' => '2026-11-25',
            'start_time' => '09:00', 'end_time' => '10:00', 'remind_minutes' => 30,
        ])->assertCreated()->assertJsonPath('data.remind_minutes', 30)->json('data.id');

        $this->withToken($jwt)->getJson("$path?from=2026-11-01&to=2026-11-30")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Vistoria cautelar');
        $this->withToken($jwt)->getJson("$path?from=2026-12-01&to=2026-12-31")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($jwt)->patchJson("$path/$id", ['status' => 'done'])
            ->assertOk()->assertJsonPath('data.status', 'done');

        $documentId = $this->withToken($jwt)->post("/api/v1/repasse-cases/$case/evidence", [
            'document_type' => 'outros', 'document_label' => 'Anexo do agendamento',
            'file' => UploadedFile::fake()->create('laudo.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->withToken($jwt)->postJson("$path/$id/attachments", [
            'evidence_id' => $documentId,
        ])->assertOk()->assertJsonPath('data.0.id', $documentId);
        $this->withToken($jwt)->postJson("$path/$id/attachments", [
            'evidence_id' => $documentId,
        ])->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($jwt)->getJson("$path/$id/attachments")
            ->assertOk()->assertJsonCount(1, 'data');

        $alienEvidence = $this->withToken($jwt)->post("/api/v1/repasse-cases/$otherCase/evidence", [
            'document_type' => 'outros', 'document_label' => 'Arquivo de outro carro',
            'file' => UploadedFile::fake()->create('outro.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->withToken($jwt)->postJson("$path/$id/attachments", [
            'evidence_id' => $alienEvidence,
        ])->assertNotFound();
    }

    public function test_session_permissions_tenant_and_archived_case_block_mutations(): void
    {
        $path = '/api/v1/operations/calendar';
        $this->getJson("$path?from=2026-11-01&to=2026-11-30")->assertUnauthorized();
        $unprivileged = $this->token(null, false);
        $this->withToken($unprivileged)->getJson("$path?from=2026-11-01&to=2026-11-30")
            ->assertForbidden();

        $jwt = $this->token();
        $case = $this->vehicle($jwt);
        $otherTenant = Tenant::query()->create([
            'name' => 'Empresa isolada', 'slug' => 'outra-empresa', 'is_active' => true,
        ]);
        $otherJwt = $this->token($otherTenant->id);

        $this->withToken($otherJwt)->getJson("/api/v1/repasse-cases/$case/checklists")
            ->assertNotFound();
        $this->withToken($otherJwt)->postJson('/api/v1/operations/calendar', [
            'repasse_case_id' => $case, 'title' => 'Invasão de outra empresa',
            'kind' => 'reminder', 'due_on' => '2026-11-25',
        ])->assertNotFound();

        $this->archiveVehicleForQa($jwt, "/api/v1/repasse-cases/$case");
        $this->withToken($jwt)->postJson("/api/v1/repasse-cases/$case/checklists",
            $this->checklistPayload())->assertUnprocessable();
        $this->withToken($jwt)->postJson($path, [
            'repasse_case_id' => $case, 'title' => 'Compromisso sem edição',
            'kind' => 'task', 'due_on' => '2026-11-25',
        ])->assertUnprocessable();
    }
}
