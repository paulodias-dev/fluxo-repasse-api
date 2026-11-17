<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VehicleCloseoutReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('closeout-review-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
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

    public function test_closeout_review_is_guarded_and_does_not_expose_other_vehicles(): void
    {
        $endpoint = '/api/v1/repasse-cases/99/closeout-review';
        $this->getJson($endpoint)->assertUnauthorized();
        $this->withToken('invalid-jwt')->getJson($endpoint)->assertUnauthorized();

        $withoutGrant = $this->token(false);
        $this->withToken($withoutGrant)->getJson($endpoint)->assertForbidden();

        $allowed = $this->token();
        $this->withToken($allowed)->getJson($endpoint)->assertNotFound();
        $this->withToken($allowed)->getJson('/api/v1/repasse-cases/0/closeout-review')
            ->assertNotFound();
    }

    public function test_closeout_review_counts_all_tasks_and_preserves_signed_financial_result(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo para encerrar', 'purchase_amount_cents' => 5000000,
            'sale_amount_cents' => 7000000, 'sold_on' => '2026-11-14',
        ])->assertCreated()->json('data.id');
        $base = "/api/v1/repasse-cases/{$id}";
        for ($i = 1; $i <= 17; $i++) {
            $this->withToken($token)->postJson("{$base}/tasks", [
                'title' => "Acompanhar pendência {$i}",
            ])->assertCreated();
        }
        $this->withToken($token)->postJson("{$base}/checklist", [
            'label' => 'Conferir documentação de saída',
        ])->assertCreated();
        foreach ([false, true] as $archived) {
            DB::table('repasse_case_evidence')->insert([
                'repasse_case_id' => $id,
                'original_name' => 'comprovante.pdf',
                'storage_path' => 'tests/closeout-'.$id.'-'.$archived.'.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => 512,
                'sha256' => str_repeat('a', 64),
                'archived_at' => $archived ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $expense = $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
            'description' => 'Custos realizados', 'amount_cents' => 200000,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'Transporte',
            'description' => 'Custo ainda previsto', 'amount_cents' => 30000,
        ])->assertCreated();
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'income', 'status' => 'planned', 'category' => 'Serviço extra',
            'description' => 'Receita ainda prevista', 'amount_cents' => 20000,
        ])->assertCreated();
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'income', 'status' => 'realized', 'category' => 'Serviço extra',
            'description' => 'Receita realizada', 'amount_cents' => 10000,
        ])->assertCreated();
        $voided = $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
            'description' => 'Lançamento estornado', 'amount_cents' => 400000,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("{$base}/finance/entries/{$voided}/void", [])
            ->assertOk();

        $review = $this->withToken($token)->getJson("{$base}/closeout-review")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.purchase_amount_cents', 5000000)
            ->assertJsonPath('data.sale_amount_cents', 7000000)
            ->assertJsonPath('data.current_cost_cents', 5200000)
            ->assertJsonPath('data.sale_result_cents', 1810000)
            ->assertJsonPath('data.expense_realized_cents', 200000)
            ->assertJsonPath('data.income_realized_cents', 10000)
            ->assertJsonPath('data.expense_planned_cents', 30000)
            ->assertJsonPath('data.income_planned_cents', 20000)
            ->assertJsonPath('data.expense_planned_count', 1)
            ->assertJsonPath('data.income_planned_count', 1)
            ->assertJsonPath('data.open_tasks_count', 17)
            ->assertJsonPath('data.pending_checklist_count', 1)
            ->assertJsonPath('data.documents_count', 1);

        $this->withToken($token)->getJson("{$base}/finance/summary")
            ->assertOk()->assertJsonPath('data.sale_result_cents', 1810000);
        $this->assertNotSame($expense, $voided);
        $this->assertArrayHasKey('as_of', $review->json());

        // Arquivamento não quita operações nem apaga tarefas/documentos;
        // o operador poderá reabrir a ficha mais tarde.
        $this->withToken($token)->patchJson($base, ['status' => 'archived'])
            ->assertOk()->assertJsonPath('data.status', 'archived');
        $this->withToken($token)->getJson("{$base}/closeout-review")
            ->assertOk()->assertJsonPath('data.open_tasks_count', 17)
            ->assertJsonPath('data.sale_result_cents', 1810000)
            ->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'case_archived',
        ]);
        $this->withToken($token)->patchJson($base, ['status' => 'draft'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'case_reopened',
        ]);
    }

    public function test_missing_purchase_and_sale_remain_unknown_not_zero_or_automatic_sale(): void
    {
        $token = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Acompanhamento ainda sem venda',
        ])->assertCreated()->json('data.id');

        $endpoint = "/api/v1/repasse-cases/{$id}/closeout-review";
        $this->withToken($token)->getJson($endpoint)->assertOk()
            ->assertJsonPath('data.purchase_amount_cents', null)
            ->assertJsonPath('data.sale_amount_cents', null)
            ->assertJsonPath('data.sale_result_cents', null)
            ->assertJsonPath('data.current_cost_cents', null)
            ->assertJsonPath('data.sold_on', null)
            ->assertJsonPath('data.open_tasks_count', 0)
            ->assertJsonPath('data.pending_checklist_count', 0)
            ->assertJsonPath('data.documents_count', 0);

        // Encerrar acompanhamento não exige venda fictícia nem data inventada.
        $this->withToken($token)->patchJson("/api/v1/repasse-cases/{$id}", [
            'status' => 'archived',
        ])->assertOk();
        $this->withToken($token)->getJson($endpoint)->assertOk()
            ->assertJsonPath('data.sold_on', null)
            ->assertJsonPath('data.sale_result_cents', null)
            ->assertJsonPath('data.status', 'archived');
    }
}
