<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VehicleFinanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('vehicle-finance-360-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(bool $allowed = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(
                ['slug' => 'finance_vehicle_operator'], ['name' => 'Operador gerencial']
            );
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $token, string $name = 'Veículo de homologação'): int
    {
        return $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => $name, 'purchase_amount_cents' => 5000000,
            'sale_amount_cents' => 7000000,
        ])->assertCreated()->json('data.id');
    }

    private function entryInput(array $override = []): array
    {
        return array_replace([
            'kind' => 'expense', 'status' => 'realized',
            'category' => 'Manutenção', 'description' => 'Troca de pneus',
            'amount_cents' => 120000, 'effective_on' => '2026-11-16',
        ], $override);
    }

    private function entry(string $token, int $case, array $data = []): int
    {
        return $this->withToken($token)->postJson(
            "/api/v1/repasse-cases/{$case}/finance/entries",
            $this->entryInput($data)
        )->assertCreated()->json('data.id');
    }

    public function test_session_and_case_permissions_protect_all_new_routes(): void
    {
        $paths = [
            '/api/v1/repasse-cases/1/finance/categories',
            '/api/v1/repasse-cases/1/finance/report',
            '/api/v1/repasse-cases/1/finance/export',
            '/api/v1/repasse-cases/1/finance/entries/1',
        ];
        foreach ($paths as $path) $this->getJson($path)->assertUnauthorized();

        $token = $this->token(false);
        foreach ($paths as $path) $this->withToken($token)->getJson($path)->assertForbidden();

        $this->withToken($token)->postJson('/api/v1/repasse-cases/1/finance/categories', [
            'kind' => 'expense', 'name' => 'Manutenção',
        ])->assertForbidden();
    }

    public function test_category_is_editable_and_historical_entry_name_is_immutable(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/{$vehicle}/finance";
        $category = $this->withToken($token)->postJson("$base/categories", [
            'kind' => 'expense', 'name' => 'Mecânica',
        ])->assertCreated()->json('data.id');

        $entry = $this->entry($token, $vehicle, [
            'category_id' => $category, 'category' => 'Texto diferente',
        ]);
        $this->withToken($token)->patchJson("$base/categories/$category", [
            'name' => 'Serviços de oficina',
        ])->assertOk();
        $this->withToken($token)->getJson("$base/entries/$entry")
            ->assertOk()->assertJsonPath('data.entry.category', 'Mecânica')
            ->assertJsonPath('data.entry.category_id', $category);
        $this->withToken($token)->postJson("$base/categories", [
            'kind' => 'expense', 'name' => 'Serviços de oficina',
        ])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->withToken($token)->patchJson("$base/categories/$category", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($token)->postJson("$base/entries", [
            ...$this->entryInput(), 'category_id' => $category,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->withToken($token)->postJson("$base/entries", [
            ...$this->entryInput(['category' => 'Serviços de oficina']),
        ])->assertUnprocessable()->assertJsonValidationErrors('category');

        $this->withToken($token)->getJson("$base/categories")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson("$base/categories?include_inactive=1")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('repasse_case_financial_entries', 1);
    }

    public function test_private_receipt_upload_link_and_archive_are_scoped_to_original_entry(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $entry = $this->entry($token, $vehicle);
        $base = "/api/v1/repasse-cases/{$vehicle}/finance/entries/$entry";

        $response = $this->withToken($token)->post("$base/documents", [
            'file' => UploadedFile::fake()->create('comprovante.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonMissingPath('data.documents.0.storage_path');

        $document = $response->json('data.documents.0.id');
        $this->withToken($token)->get("$base/documents/$document/download")
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.documents.0.id', $document);
        $this->assertDatabaseHas('repasse_case_financial_events', [
            'repasse_case_id' => $vehicle, 'entry_id' => $entry,
            'event_type' => 'document_attached',
        ]);

        // The underlying private evidence can be archived; finance MUST NOT
        // bypass the existing evidence-download restriction.
        $this->withToken($token)->patchJson(
            "/api/v1/repasse-cases/$vehicle/evidence/$document/archive", []
        )->assertOk();
        $this->withToken($token)->get("$base/documents/$document/download")->assertNotFound();
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.documents.0.id', $document);
    }

    public function test_existing_receipt_cannot_be_linked_across_vehicles_or_two_entries(): void
    {
        $token = $this->token();
        $first = $this->vehicle($token, 'Veículo de origem');
        $second = $this->vehicle($token, 'Veículo de destino');
        $entryA = $this->entry($token, $first);
        $entryB = $this->entry($token, $first);
        $foreignEntry = $this->entry($token, $second);
        $upload = $this->withToken($token)->post("/api/v1/repasse-cases/$first/evidence", [
            'file' => UploadedFile::fake()->create('nota.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $evidence = $upload->json('data.id');
        $base = "/api/v1/repasse-cases/$first/finance/entries";

        $this->withToken($token)->postJson("$base/$entryA/documents", [
            'evidence_id' => $evidence,
        ])->assertCreated()->assertJsonPath('created', true);
        $this->withToken($token)->postJson("$base/$entryA/documents", [
            'evidence_id' => $evidence,
        ])->assertOk()->assertJsonPath('created', false);
        $this->withToken($token)->postJson("$base/$entryB/documents", [
            'evidence_id' => $evidence,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson(
            "/api/v1/repasse-cases/$second/finance/entries/$foreignEntry/documents",
            ['evidence_id' => $evidence]
        )->assertUnprocessable();
        $this->withToken($token)->get(
            "/api/v1/repasse-cases/$second/finance/entries/$foreignEntry/documents/$evidence/download"
        )->assertNotFound();
        $this->withToken($token)->deleteJson(
            "$base/$entryA/documents/$evidence"
        )->assertOk()->assertJsonCount(0, 'data.documents');

        // Detaching never deletes the vehicle's original evidence.
        $this->withToken($token)->get(
            "/api/v1/repasse-cases/$first/evidence/$evidence/download"
        )->assertOk();
        $this->assertDatabaseCount('repasse_case_financial_documents', 0);
    }

    public function test_atomic_correction_preserves_original_documents_and_audit(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $old = $this->entry($token, $vehicle);
        $base = "/api/v1/repasse-cases/$vehicle/finance";
        $document = $this->withToken($token)->post("$base/entries/$old/documents", [
            'file' => UploadedFile::fake()->create('antes.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.documents.0.id');

        $next = $this->withToken($token)->postJson("$base/entries/$old/replace", [
            ...$this->entryInput([
                'status' => 'planned', 'amount_cents' => 90000,
                'description' => 'Valor corrigido',
            ]),
            'reason' => 'Erro de digitação no valor original.',
        ])->assertCreated()->assertJsonPath('replaces_entry_id', $old)
            ->json('data.id');

        $this->withToken($token)->getJson("$base/entries/$old")
            ->assertOk()->assertJsonPath('data.entry.void_reason', 'Erro de digitação no valor original.')
            ->assertJsonPath('data.documents.0.id', $document);
        $this->withToken($token)->getJson("$base/entries/$next")
            ->assertOk()->assertJsonPath('data.entry.replaces_entry_id', $old)
            ->assertJsonCount(0, 'data.documents');
        $this->withToken($token)->getJson("$base/summary")
            ->assertOk()->assertJsonPath('data.expense_realized_cents', 0)
            ->assertJsonPath('data.expense_planned_cents', 90000)
            ->assertJsonPath('data.projected_cost_cents', 5090000);
        $this->withToken($token)->postJson("$base/entries/$old/replace", [
            ...$this->entryInput(), 'reason' => 'Não pode repetir a retificação',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('repasse_case_financial_events', [
            'repasse_case_id' => $vehicle, 'entry_id' => $old, 'event_type' => 'replaced',
        ]);
        $this->assertDatabaseHas('repasse_case_financial_events', [
            'repasse_case_id' => $vehicle, 'entry_id' => $next,
            'event_type' => 'created_from_replacement',
        ]);
        $this->assertDatabaseCount('repasse_case_financial_entries', 2);
    }

    public function test_planned_finance_is_realized_in_place_preserving_receipts_and_totals(): void
    {
        $jwt = $this->token();
        $vehicle = $this->vehicle($jwt);
        $base = "/api/v1/repasse-cases/{$vehicle}/finance";

        $expense = $this->entry($jwt, $vehicle, [
            'status' => 'planned', 'amount_cents' => 150000, 'effective_on' => '2026-11-15',
        ]);
        $income = $this->entry($jwt, $vehicle, [
            'kind' => 'income', 'status' => 'planned', 'category' => 'Frete extra',
            'description' => 'Receita prevista', 'amount_cents' => 25000,
        ]);
        $document = $this->withToken($jwt)->post("$base/entries/$expense/documents", [
            'file' => UploadedFile::fake()->create('recibo-previsto.pdf', 24, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.documents.0.id');
        $today = now()->toDateString();

        $this->withToken($jwt)->postJson("$base/entries/$expense/realize", [
            'effective_on' => $today,
        ])->assertOk()->assertJsonPath('changed', true)
            ->assertJsonPath('payment_executed', false)
            ->assertJsonPath('data.id', $expense)
            ->assertJsonPath('data.status', 'realized')
            ->assertJsonPath('data.effective_on', $today)
            ->assertJsonPath('data.amount_cents', 150000);

        $this->withToken($jwt)->postJson("$base/entries/$income/realize", [
            'effective_on' => $today,
        ])->assertOk()->assertJsonPath('changed', true)
            ->assertJsonPath('data.id', $income);

        // Double-tap or retry cannot create a second event or entry.
        $this->withToken($jwt)->postJson("$base/entries/$expense/realize", [
            'effective_on' => $today,
        ])->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(1, DB::table('repasse_case_financial_events')
            ->where('entry_id', $expense)->where('event_type', 'realized')->count());

        $this->withToken($jwt)->postJson("$base/entries/$expense/realize", [
            'effective_on' => '2026-11-16',
        ])->assertConflict();
        $this->withToken($jwt)->getJson("$base/entries/$expense")
            ->assertOk()->assertJsonPath('data.documents.0.id', $document)
            ->assertJsonPath('data.entry.voided_at', null);
        $this->withToken($jwt)->getJson("$base/summary")
            ->assertOk()
            ->assertJsonPath('data.expense_planned_cents', 0)
            ->assertJsonPath('data.expense_realized_cents', 150000)
            ->assertJsonPath('data.income_planned_cents', 0)
            ->assertJsonPath('data.income_realized_cents', 25000);
        $this->assertDatabaseCount('repasse_case_financial_entries', 2);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $vehicle, 'event_type' => 'vehicle_entry_realized',
        ]);
    }

    public function test_realization_rejects_other_vehicles_voids_future_dates_and_unprivileged_users(): void
    {
        $this->postJson('/api/v1/repasse-cases/1/finance/entries/1/realize', [
            'effective_on' => now()->toDateString(),
        ])->assertUnauthorized();
        $unprivileged = $this->token(false);
        $this->withToken($unprivileged)
            ->postJson('/api/v1/repasse-cases/1/finance/entries/1/realize', [
                'effective_on' => now()->toDateString(),
            ])->assertForbidden();

        $jwt = $this->token();
        $first = $this->vehicle($jwt, 'Primeiro veículo');
        $second = $this->vehicle($jwt, 'Outro veículo');
        $planned = $this->entry($jwt, $first, ['status' => 'planned']);
        $base = "/api/v1/repasse-cases/$first/finance/entries/$planned/realize";

        $this->withToken($jwt)->postJson($base, [])->assertUnprocessable()
            ->assertJsonValidationErrors('effective_on');
        $this->withToken($jwt)->postJson($base, [
            'effective_on' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_on');
        $this->withToken($jwt)->postJson($base, [
            'effective_on' => now()->toDateString(), 'payment_confirmation' => 'fake',
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_confirmation');
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/$second/finance/entries/$planned/realize",
            ['effective_on' => now()->toDateString()]
        )->assertNotFound();

        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/$first/finance/entries/$planned/void",
            ['reason' => 'Lançamento cancelado antes de ser realizado']
        )->assertOk();
        $this->withToken($jwt)->postJson($base, [
            'effective_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('entry');

        $other = $this->entry($jwt, $first, ['status' => 'planned']);
        $this->withToken($jwt)->patchJson("/api/v1/repasse-cases/$first", [
            'status' => 'archived',
        ])->assertOk();
        $this->withToken($jwt)->postJson(
            "/api/v1/repasse-cases/$first/finance/entries/$other/realize",
            ['effective_on' => now()->toDateString()]
        )->assertUnprocessable();
    }

    public function test_reports_filter_precise_cents_and_export_sanitizes_spreadsheet_cells(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/$vehicle/finance";
        $a = $this->entry($token, $vehicle, [
            'category' => 'Oficina', 'amount_cents' => 100001,
            'effective_on' => '2026-11-10',
        ]);
        $this->entry($token, $vehicle, [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'Oficina',
            'amount_cents' => 20000, 'effective_on' => '2026-12-10',
        ]);
        $this->entry($token, $vehicle, [
            'kind' => 'income', 'status' => 'realized', 'category' => '=HYPERLINK("bad")',
            'description' => '=CMD', 'amount_cents' => 4000,
            'effective_on' => '2026-11-15',
        ]);
        $this->withToken($token)->postJson("$base/entries/$a/void", [
            'reason' => 'Duplicidade conferida',
        ])->assertOk();
        $this->withToken($token)->getJson("$base/report")
            ->assertOk()->assertJsonPath('data.summary.entries_total', 2)
            ->assertJsonPath('data.summary.expense_realized_cents', 0)
            ->assertJsonPath('data.summary.expense_planned_cents', 20000)
            ->assertJsonPath('data.summary.income_realized_cents', 4000)
            ->assertJsonCount(2, 'data.by_category.data');
        $this->withToken($token)->getJson("$base/report?from=2026-11-01&to=2026-11-30")
            ->assertOk()->assertJsonPath('data.summary.entries_total', 1);
        $this->withToken($token)->getJson("$base/report?include_voided=1")
            ->assertOk()->assertJsonPath('data.summary.entries_total', 3)
            ->assertJsonPath('data.summary.expense_realized_cents', 100001);
        $this->withToken($token)->getJson("$base/report?from=2026-12-31&to=2026-01-01")
            ->assertUnprocessable()->assertJsonValidationErrors('to');

        $csv = $this->withToken($token)->get("$base/export?include_voided=1")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();
        $this->assertStringContainsString("'=CMD", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('100001', $csv);
        $this->assertStringContainsString('Duplicidade conferida', $csv);
    }

    public function test_archived_vehicle_blocks_financial_mutations_but_allows_reports(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $old = $this->entry($token, $vehicle);
        $base = "/api/v1/repasse-cases/$vehicle/finance";
        $this->withToken($token)->patchJson(
            "/api/v1/repasse-cases/$vehicle", ['status' => 'archived']
        )->assertOk();

        $this->withToken($token)->getJson("$base/entries")->assertOk();
        $this->withToken($token)->getJson("$base/report")->assertOk();
        $this->withToken($token)->getJson("$base/entries/$old")->assertOk();
        $this->withToken($token)->postJson("$base/entries", $this->entryInput())
            ->assertUnprocessable();
        $this->withToken($token)->postJson("$base/entries/$old/replace", [
            ...$this->entryInput(), 'reason' => 'Veículo já arquivado',
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/entries/$old/documents", [
            'evidence_id' => 1,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/categories", [
            'kind' => 'expense', 'name' => 'Nova categoria',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('repasse_case_financial_entries', 1);
    }

    public function test_audit_history_is_fully_paginated_and_not_silently_truncated(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $entry = $this->entry($token, $vehicle);
        for ($i = 1; $i <= 35; $i++) {
            DB::table('repasse_case_financial_events')->insert([
                'repasse_case_id' => $vehicle,
                'entry_id' => $entry,
                'event_type' => 'qa_event',
                'details' => json_encode(['sequence' => $i], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }

        $base = "/api/v1/repasse-cases/$vehicle/finance/entries/$entry";
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.events_total', 36)
            ->assertJsonCount(30, 'data.events');
        $this->withToken($token)->getJson("$base/events?page=2")
            ->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('total', 36)
            ->assertJsonPath('current_page', 2);
    }

    public function test_legacy_entry_contract_and_pagination_remain_compatible(): void
    {
        $token = $this->token();
        $vehicle = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/$vehicle/finance";
        for ($i = 0; $i < 32; $i++) {
            $this->entry($token, $vehicle, [
                'description' => 'Lançamento legado '.$i,
            ]);
        }
        $this->withToken($token)->getJson("$base/entries")
            ->assertOk()->assertJsonCount(30, 'data')
            ->assertJsonPath('total', 32);
        $this->withToken($token)->getJson("$base/entries?page=2")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("$base/entries?kind=payments")->assertUnprocessable();
        $this->withToken($token)->getJson("$base/entries?from=2027-01-01&to=2026-01-01")
            ->assertUnprocessable();
        $this->withToken($token)->getJson("$base/summary")
            ->assertOk()->assertJsonPath('data.expense_realized_cents', 3840000);
    }
}
