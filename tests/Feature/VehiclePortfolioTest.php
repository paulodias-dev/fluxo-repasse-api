<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VehiclePortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('portfolio-qa-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
    }

    private function token(bool $allowed = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(['slug' => 'fleet_operator'], ['name' => 'Operação de frota']);
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $token, array $profile): int
    {
        return $this->withToken($token)->postJson('/api/v1/repasse-cases', $profile)
            ->assertCreated()->json('data.id');
    }

    private function task(string $token, int $id, string $due, string $status = 'pending'): void
    {
        $endpoint = "/api/v1/repasse-cases/{$id}/tasks";
        $idTask = $this->withToken($token)->postJson($endpoint, [
            'title' => 'Conferir entrega', 'priority' => 'high', 'due_on' => $due,
        ])->assertCreated()->json('data.id');
        if ($status !== 'pending') {
            $this->withToken($token)->patchJson("$endpoint/$idTask", [
                'status' => $status,
            ])->assertOk();
        }
    }

    private function evidence(int $id, string $path, bool $archived = false): void
    {
        DB::table('repasse_case_evidence')->insert([
            'repasse_case_id' => $id,
            'original_name' => 'nota-fiscal.pdf',
            'storage_path' => $path,
            'mime' => 'application/pdf',
            'size_bytes' => 2000,
            'sha256' => str_repeat('b', 64),
            'archived_at' => $archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_anonymous_and_unprivileged_users_cannot_see_portfolio(): void
    {
        $this->getJson('/api/v1/operations/vehicles')->assertUnauthorized();
        $token = $this->token(false);
        $this->withToken($token)->getJson('/api/v1/operations/vehicles')->assertForbidden();
    }

    public function test_empty_portfolio_is_stable_and_never_invents_financial_values(): void
    {
        $token = $this->token();
        $this->withToken($token)->getJson('/api/v1/operations/vehicles')->assertOk()
            ->assertJsonPath('summary.vehicles_total', 0)
            ->assertJsonPath('summary.vehicles_overdue', 0)
            ->assertJsonPath('summary.purchase_total_cents', 0)
            ->assertJsonPath('summary.expense_realized_total_cents', 0)
            ->assertJsonPath('summary.projected_result_known_total_cents', 0)
            ->assertJsonPath('summary.vehicles_with_projected_result', 0)
            ->assertJsonPath('summary.vehicles_with_sale_result', 0)
            ->assertJsonPath('summary.sale_result_known_total_cents', 0)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total', 0);
    }

    public function test_aggregates_each_vehicle_without_multiplying_tasks_documents_or_money(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token, [
            'title' => 'Chevrolet Camaro', 'plate' => 'ABC1D23', 'brand' => 'Chevrolet',
            'model' => 'Camaro', 'purchase_amount_cents' => 5000000,
            'sale_amount_cents' => 7000000,
        ]);
        $base = "/api/v1/repasse-cases/{$id}";
        $this->task($token, $id, '2026-11-10');
        $this->task($token, $id, '2026-11-15', 'doing');
        $this->task($token, $id, '2026-11-05', 'done');
        $this->withToken($token)->postJson("$base/checklist", [
            'label' => 'Conferir documentos',
        ])->assertCreated();
        $this->evidence($id, "test/{$id}-1.pdf");
        $this->evidence($id, "test/{$id}-2.pdf", true);

        foreach ([
            ['expense', 'realized', 100000, 'Oficina'],
            ['expense', 'planned', 50000, 'Transporte'],
            ['income', 'realized', 20000, 'Receita acessória'],
            ['income', 'planned', 10000, 'Outra receita'],
        ] as [$kind, $status, $amount, $category]) {
            $this->withToken($token)->postJson("$base/finance/entries", [
                'kind' => $kind, 'status' => $status, 'category' => $category,
                'description' => 'Lançamento gerencial', 'amount_cents' => $amount,
            ])->assertCreated();
        }

        $voided = $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
            'description' => 'Estornado', 'amount_cents' => 900000,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("$base/finance/entries/$voided/void", [])
            ->assertOk();

        $this->withToken($token)->getJson('/api/v1/operations/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.open_tasks', 2)
            ->assertJsonPath('data.0.overdue_tasks', 2)
            ->assertJsonPath('data.0.pending_checklist', 1)
            ->assertJsonPath('data.0.documents_count', 1)
            ->assertJsonPath('data.0.expense_realized_cents', 100000)
            ->assertJsonPath('data.0.expense_planned_cents', 50000)
            ->assertJsonPath('data.0.current_cost_cents', 5100000)
            ->assertJsonPath('data.0.projected_cost_cents', 5150000)
            ->assertJsonPath('data.0.projected_result_cents', 1880000)
            ->assertJsonPath('data.0.sale_result_cents', null)
            ->assertJsonPath('summary.vehicles_total', 1)
            ->assertJsonPath('summary.vehicles_overdue', 1)
            ->assertJsonPath('summary.vehicles_with_open_tasks', 1)
            ->assertJsonPath('summary.vehicles_without_documents', 0)
            ->assertJsonPath('summary.vehicles_incomplete_profile', 0)
            ->assertJsonPath('summary.vehicles_with_planned_expenses', 1)
            ->assertJsonPath('summary.purchase_total_cents', 5000000)
            ->assertJsonPath('summary.sale_total_cents', 7000000)
            ->assertJsonPath('summary.expense_realized_total_cents', 100000)
            ->assertJsonPath('summary.expense_planned_total_cents', 50000)
            ->assertJsonPath('summary.income_realized_total_cents', 20000)
            ->assertJsonPath('summary.income_planned_total_cents', 10000)
            ->assertJsonPath('summary.vehicles_with_projected_result', 1)
            ->assertJsonPath('summary.projected_result_known_total_cents', 1880000)
            ->assertJsonPath('summary.vehicles_with_sale_result', 0)
            ->assertJsonPath('summary.sale_result_known_total_cents', 0);

        // Sale date explicitly distinguishes recorded sale from undated estimate,
        // WITHOUT treating it as confirmation of receiving bank funds.
        $this->withToken($token)->patchJson($base, [
            'sold_on' => '2026-11-15',
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/operations/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.sale_result_cents', 1920000)
            ->assertJsonPath('data.0.projected_result_cents', 1880000)
            ->assertJsonPath('summary.vehicles_with_sale_result', 1)
            ->assertJsonPath('summary.sale_result_known_total_cents', 1920000);
        $this->withToken($token)->getJson("$base/finance/summary")
            ->assertOk()->assertJsonPath('data.sale_result_cents', 1920000);
    }

    public function test_attention_filters_ignore_archived_vehicles_and_do_not_conflate_null_with_zero(): void
    {
        $token = $this->token();
        $complete = $this->vehicle($token, [
            'title' => 'Toyota Etios', 'plate' => 'ABC1D23',
            'brand' => 'Toyota', 'model' => 'Etios', 'purchase_amount_cents' => 0,
        ]);
        $this->evidence($complete, 'test/etios.pdf');

        $needsProfile = $this->vehicle($token, ['title' => 'Veículo sem documentação']);
        $this->task($token, $needsProfile, '2026-11-15');
        $archived = $this->vehicle($token, ['title' => 'Veículo arquivado']);
        $this->task($token, $archived, '2026-11-01');
        $this->withToken($token)->patchJson("/api/v1/repasse-cases/$archived", [
            'status' => 'archived',
        ])->assertOk();

        $base = '/api/v1/operations/vehicles';
        $this->withToken($token)->getJson("$base?attention=overdue")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $needsProfile)
            ->assertJsonPath('summary.vehicles_overdue', 1);
        $this->withToken($token)->getJson("$base?attention=missing_documents")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $needsProfile);
        $this->withToken($token)->getJson("$base?attention=incomplete_profile")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $needsProfile);
        $this->withToken($token)->getJson("$base?status=archived")
            ->assertOk()->assertJsonPath('data.0.id', $archived)
            ->assertJsonPath('summary.vehicles_active', 2)
            ->assertJsonPath('summary.vehicles_archived', 1);
        $this->withToken($token)->getJson("$base?search=Toyota")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $complete);
        $this->withToken($token)->getJson("$base?search=%25")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson("$base?search=Ve%C3%ADculo&attention=overdue")
            ->assertOk()->assertJsonPath('data.0.id', $needsProfile);
        $this->withToken($token)->getJson("$base?attention=planned_expenses")->assertOk()
            ->assertJsonCount(0, 'data');
        $response = $this->withToken($token)->getJson("$base?search=Toyota");
        $response->assertJsonPath('data.0.current_cost_cents', 0)
            ->assertJsonPath('data.0.projected_result_cents', null)
            ->assertJsonPath('data.0.sale_result_cents', null);
        $response->assertJsonPath('summary.vehicles_with_projected_result', 0)
            ->assertJsonPath('summary.projected_result_known_total_cents', 0);
        $this->withToken($token)->getJson("$base?search=documenta%C3%A7%C3%A3o")
            ->assertJsonPath('data.0.current_cost_cents', null);
    }

    public function test_negative_projected_result_is_preserved_without_unsigned_overflow(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token, [
            'title' => 'Veículo com prejuízo gerencial',
            'purchase_amount_cents' => 5000000,
            'sale_amount_cents' => 4000000,
        ]);
        $this->withToken($token)->postJson("/api/v1/repasse-cases/$id/finance/entries", [
            'kind' => 'expense', 'status' => 'planned',
            'category' => 'Manutenção', 'description' => 'Revisão prevista',
            'amount_cents' => 500000,
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/v1/operations/vehicles')
            ->assertOk()
            ->assertJsonPath('data.0.current_cost_cents', 5000000)
            ->assertJsonPath('data.0.projected_cost_cents', 5500000)
            ->assertJsonPath('data.0.projected_result_cents', -1500000)
            ->assertJsonPath('summary.vehicles_with_planned_expenses', 1)
            ->assertJsonPath('summary.projected_result_known_total_cents', -1500000);
        $this->withToken($token)->getJson("/api/v1/repasse-cases/$id/finance/summary")
            ->assertOk()->assertJsonPath('data.projected_result_cents', -1500000);
    }

    public function test_priority_sort_follows_actionable_operational_work_not_money_or_archived(): void
    {
        $token = $this->token();
        $complete = [
            'plate' => 'ABC1D23', 'brand' => 'Toyota', 'model' => 'Etios',
            'purchase_amount_cents' => 1200000,
        ];
        // Registro neutro e perfis incompletos não ganham prioridade
        // artificial sobre trabalho operacional vencido.
        $neutral = $this->vehicle($token, ['title' => 'Sem pendências', ...$complete]);
        $profile = $this->vehicle($token, ['title' => 'Identificação a completar']);
        $planned = $this->vehicle($token, ['title' => 'Despesa prevista', ...$complete]);
        $open = $this->vehicle($token, ['title' => 'Tarefa aberta', ...$complete]);
        $overdue = $this->vehicle($token, ['title' => 'Tarefa vencida', ...$complete]);
        $archived = $this->vehicle($token, ['title' => 'Veículo arquivado', ...$complete]);

        $this->withToken($token)->postJson("/api/v1/repasse-cases/{$planned}/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'Manutenção',
            'description' => 'Revisão ainda prevista', 'amount_cents' => 30000,
        ])->assertCreated();
        $this->task($token, $open, '2026-11-21');
        $this->task($token, $overdue, '2026-11-15');
        $this->task($token, $archived, '2026-11-01');
        $this->withToken($token)->patchJson("/api/v1/repasse-cases/{$archived}", [
            'status' => 'archived',
        ])->assertOk();

        $base = '/api/v1/operations/vehicles';
        $result = $this->withToken($token)->getJson("$base?sort=priority")
            ->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('summary.vehicles_overdue', 1)
            ->assertJsonPath('summary.vehicles_with_planned_expenses', 1)
            ->json('data');
        $this->assertSame(
            [$overdue, $open, $planned, $profile, $neutral, $archived],
            array_column($result, 'id'),
        );

        // Filters retain their existing meaning. Archived vehicles never
        // enter attention-only results, and totals describe the whole fleet.
        $this->withToken($token)->getJson("$base?sort=priority&attention=overdue")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue)
            ->assertJsonPath('summary.vehicles_active', 5)
            ->assertJsonPath('summary.vehicles_archived', 1);
        $this->withToken($token)->getJson("$base?sort=priority&attention=planned_expenses")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $planned);
    }

    public function test_validation_and_stable_pagination(): void
    {
        $token = $this->token();
        for ($i = 1; $i <= 17; $i++) {
            $this->vehicle($token, ['title' => "Veículo #{$i}"]);
        }
        $base = '/api/v1/operations/vehicles';
        $this->withToken($token)->getJson("$base?sort=oldest&page=2")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 17)
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 2);
        $this->withToken($token)->getJson("$base?attention=payments")
            ->assertUnprocessable()->assertJsonValidationErrors('attention');
        $this->withToken($token)->getJson("$base?sort=random")
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->withToken($token)->getJson("$base?status=deleted")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }
}
