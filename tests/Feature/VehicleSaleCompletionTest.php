<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VehicleSaleCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('sale-closure-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $allowed = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(
                ['slug' => 'sale_completion_operator'], ['name' => 'Operador de frota']
            );
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $token, array $override = []): int
    {
        return $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo de teste de venda',
            'purchase_amount_cents' => 3000000,
            'purchased_on' => '2026-11-01',
            ...$override,
        ])->assertCreated()->json('data.id');
    }

    private function review(string $token, int $id): array
    {
        return $this->withToken($token)->getJson("/api/v1/repasse-cases/{$id}/closeout-review")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->json('data');
    }

    public function test_sale_and_closeout_are_one_atomic_action_without_duplicate_finance(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/{$id}";
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Oficina',
            'description' => 'Revisão técnica', 'amount_cents' => 200000,
        ])->assertCreated();
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'income', 'status' => 'realized', 'category' => 'Extras',
            'description' => 'Receita extra', 'amount_cents' => 50000,
        ])->assertCreated();
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'Transporte',
            'description' => 'Despesa ainda prevista', 'amount_cents' => 30000,
        ])->assertCreated();
        $this->withToken($token)->postJson("{$base}/tasks", [
            'title' => 'Conferir vistoria',
        ])->assertCreated();

        $review = $this->review($token, $id);
        $this->assertSame(64, strlen($review['review_sha256']));
        $this->assertSame(1, $review['open_tasks_count']);
        $this->assertSame(1, $review['expense_planned_count']);
        $payload = [
            'sale_amount_cents' => 4500000,
            'sold_on' => '2026-11-17',
            'buyer_name' => 'Comprador declarado',
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => false,
        ];
        $this->withToken($token)->postJson("{$base}/sale/complete", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('acknowledge_pending');
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.sold_on', null)
            ->assertJsonPath('data.status', 'draft');

        $this->withToken($token)->postJson("{$base}/sale/complete", [
            ...$payload, 'acknowledge_pending' => true,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.sale_amount_cents', 4500000)
            ->assertJsonPath('data.sold_on', '2026-11-17')
            ->assertJsonPath('data.buyer_name', 'Comprador declarado')
            ->assertJsonPath('pending_acknowledged', true)
            ->assertJsonPath('financial_authorization', false)
            ->assertJsonPath('payment_executed', false)
            ->assertJsonPath('obligation_created', false);

        $this->withToken($token)->getJson("{$base}/finance/summary")->assertOk()
            ->assertJsonPath('data.sale_result_cents', 1350000)
            ->assertJsonPath('data.income_realized_cents', 50000)
            ->assertJsonPath('data.expense_realized_cents', 200000);
        $this->assertDatabaseCount('repasse_case_financial_entries', 3);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'case_sale_completed',
        ]);
        $this->assertSame(1, DB::table('repasse_case_events')
            ->where('repasse_case_id', $id)
            ->where('event_type', 'case_sale_completed')->count());
        $this->withToken($token)->postJson("{$base}/sale/complete", [
            ...$payload, 'acknowledge_pending' => true,
        ])->assertUnprocessable();
        $this->assertSame(1, DB::table('repasse_case_events')
            ->where('repasse_case_id', $id)
            ->where('event_type', 'case_sale_completed')->count());
    }

    public function test_stale_review_conflict_and_invalid_date_never_record_partial_sale(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/{$id}";
        $old = $this->review($token, $id);
        $this->withToken($token)->postJson("{$base}/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'Revisão',
            'description' => 'Despesa posterior', 'amount_cents' => 1000,
        ])->assertCreated();
        $payload = [
            'sale_amount_cents' => 5000000, 'sold_on' => '2026-11-17',
            'expected_closeout_sha256' => $old['review_sha256'],
            'acknowledge_pending' => false,
        ];
        $this->withToken($token)->postJson("{$base}/sale/complete", $payload)
            ->assertConflict();
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.sale_amount_cents', null);

        $fresh = $this->review($token, $id);
        $this->assertNotSame($old['review_sha256'], $fresh['review_sha256']);
        $payload['expected_closeout_sha256'] = $fresh['review_sha256'];
        $this->withToken($token)->postJson("{$base}/sale/complete", [
            ...$payload, 'sold_on' => '2026-10-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('sold_on');
        $this->withToken($token)->postJson("{$base}/sale/complete", [
            ...$payload, 'sale_amount_cents' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('sale_amount_cents');
        $this->withToken($token)->postJson("{$base}/sale/complete", [
            ...$payload, 'payment_enabled' => true,
        ])->assertUnprocessable();
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.sold_on', null)->assertJsonPath('data.status', 'draft');
        $this->withToken($token)->postJson("{$base}/sale/complete", $payload)
            ->assertOk()->assertJsonPath('data.status', 'archived');
    }

    public function test_completion_without_sale_is_atomic_and_requires_acknowledgement_for_open_tasks(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token, [
            'purchase_amount_cents' => null, 'purchased_on' => null,
        ]);
        $base = "/api/v1/repasse-cases/{$id}";
        $this->withToken($token)->postJson("{$base}/checklist", [
            'label' => 'Aguardando documento',
        ])->assertCreated();
        $review = $this->review($token, $id);
        $this->assertNull($review['sold_on']);
        $this->assertNull($review['sale_result_cents']);
        $payload = [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => false,
        ];
        $this->withToken($token)->postJson("{$base}/complete", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('acknowledge_pending');
        $this->withToken($token)->postJson("{$base}/complete", [
            ...$payload, 'acknowledge_pending' => true,
        ])->assertOk()->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.sold_on', null)
            ->assertJsonPath('data.sale_amount_cents', null)
            ->assertJsonPath('pending_acknowledged', true)
            ->assertJsonPath('payment_executed', false);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'case_archived',
        ]);
        $this->assertDatabaseCount('repasse_case_financial_entries', 0);
    }

    public function test_completed_vehicle_without_pending_items_does_not_require_extra_confirmation(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token);
        $review = $this->review($token, $id);
        $this->withToken($token)->postJson("/api/v1/repasse-cases/{$id}/complete", [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => false,
        ])->assertOk()->assertJsonPath('pending_acknowledged', false)
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_completion_routes_validate_auth_scope_and_only_allowed_fields(): void
    {
        $token = $this->token();
        $id = $this->vehicle($token);
        $base = "/api/v1/repasse-cases/{$id}";
        $review = $this->review($token, $id);
        $payload = [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => false,
        ];
        foreach (["{$base}/complete", "{$base}/sale/complete"] as $url) {
            $this->postJson($url, $payload)->assertUnauthorized();
            $this->withToken($this->token(false))->postJson($url, $payload)
                ->assertForbidden();
            $this->withToken($token)->postJson(str_replace("/{$id}/", '/999999/', $url), $payload)
                ->assertNotFound();
            $this->withToken($token)->postJson($url, [
                ...$payload, 'financial_authorization' => true,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseMissing('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'case_sale_completed',
        ]);
        $this->withToken($token)->getJson($base)->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }
}
