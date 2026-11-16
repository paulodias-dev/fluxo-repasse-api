<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ScenarioReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('review-test-secret-', 5));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(array $grants = ['cases.manage', 'scenarios.manage']): array
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'rv_'. $user->id, 'name' => 'Revisão']);
        foreach ($grants as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        $jwt = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
        return [$user, $jwt];
    }

    private function scenario(string $token): int
    {
        $case = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Dossiê de revisão',
        ])->assertCreated()->json('data.id');
        return $this->withToken($token)->postJson('/api/v1/finance/scenarios', [
            'case_id' => $case,
            'client_reference' => (string) Str::uuid(),
            'title' => 'Cenário para parecer documental',
            'description' => null,
            'source_party_id' => null,
            'gross_amount_cents' => 10000,
            'allocations' => [],
        ])->assertCreated()->json('data.id');
    }

    private function submit(int $scenarioId, string $token, array $overrides = []): array
    {
        $payload = array_replace([
            'request_key' => (string) Str::uuid(),
            'scenario_revision' => 1,
            'request_note' => 'Solicito conferência dos documentos e dos valores previstos.',
            'due_on' => null,
            'assigned_to' => null,
        ], $overrides);
        $result = $this->withToken($token)->postJson(
            "/api/v1/finance/scenarios/{$scenarioId}/reviews", $payload
        )->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_enabled', false);
        return [$result->json('data.id'), $payload];
    }

    public function test_auth_and_reviewer_permission_are_separate(): void
    {
        $this->getJson('/api/v1/finance/reviews')->assertUnauthorized();
        [$creator, $creatorJwt] = $this->token();
        $scenario = $this->scenario($creatorJwt);
        [$reviewId] = $this->submit($scenario, $creatorJwt);
        $this->withToken($creatorJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Este é um parecer documental sem liberar dinheiro.',
        ])->assertForbidden();
        [$reader, $readerJwt] = $this->token(['cases.manage']);
        $this->withToken($readerJwt)->getJson('/api/v1/finance/reviews')->assertForbidden();
        $this->withToken($readerJwt)->getJson("/api/v1/finance/reviews/{$reviewId}")->assertForbidden();
    }

    public function test_review_request_idempotence_and_duplicate_revision_protection(): void
    {
        [$creator, $jwt] = $this->token();
        $scenario = $this->scenario($jwt);
        [$reviewId, $payload] = $this->submit($scenario, $jwt);
        $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", $payload)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $reviewId);
        $changed = $payload;
        $changed['request_note'] = 'Reutilização indevida da mesma chave de solicitação.';
        $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", $changed)
            ->assertConflict();
        $changed['request_key'] = (string) Str::uuid();
        $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", $changed)
            ->assertConflict();
        $this->assertDatabaseCount('repasse_scenario_reviews', 1);
        $this->assertDatabaseCount('repasse_scenario_review_events', 1);
    }

    public function test_separation_of_duties_assignment_review_and_event_integrity(): void
    {
        [$creator, $creatorJwt] = $this->token();
        [$reviewer, $reviewerJwt] = $this->token([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        $scenario = $this->scenario($creatorJwt);
        [$reviewId] = $this->submit($scenario, $creatorJwt, [
            'assigned_to' => $reviewer->id,
        ]);
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/comment", [
            'body' => 'Documento base conferido, verificar ainda os demais anexos.',
        ])->assertCreated();
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/decide", [
            'revision' => 1, 'decision' => 'changes_requested',
            'note' => 'Solicito anexos adicionais antes de emitir parecer final.',
        ])->assertOk()->assertJsonPath('data.status', 'changes_requested')
            ->assertJsonPath('data.decision_is_financial_authorization', false);
        $this->withToken($reviewerJwt)->getJson("/api/v1/finance/reviews/{$reviewId}/events")
            ->assertOk()->assertJsonCount(3, 'data');
        $this->withToken($reviewerJwt)->getJson("/api/v1/finance/reviews/{$reviewId}/integrity")
            ->assertOk()->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.checked_events', 3);
        $this->withToken($creatorJwt)->getJson('/api/v1/finance/reviews/summary')
            ->assertOk()->assertJsonPath('data.changes_requested', 1)
            ->assertJsonPath('data.financially_approved', 0);
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/decide", [
            'revision' => 2, 'decision' => 'reviewed',
            'note' => 'Não pode decidir novamente o mesmo parecer.',
        ])->assertUnprocessable();
    }

    public function test_author_cannot_review_even_if_promoted_to_reviewer(): void
    {
        [$creator, $creatorJwt] = $this->token([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        $scenario = $this->scenario($creatorJwt);
        [$reviewId] = $this->submit($scenario, $creatorJwt);
        $this->withToken($creatorJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Autor não pode emitir parecer sobre a própria proposta.',
        ])->assertForbidden();
        $this->assertDatabaseHas('repasse_scenario_reviews', [
            'id' => $reviewId, 'status' => 'pending',
        ]);
    }

    public function test_stale_scenario_is_blocked_and_searchable_after_revision_change(): void
    {
        [$creator, $jwt] = $this->token();
        [$reviewer, $reviewerJwt] = $this->token([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        $scenario = $this->scenario($jwt);
        [$reviewId] = $this->submit($scenario, $jwt);
        $this->withToken($jwt)->putJson("/api/v1/finance/scenarios/{$scenario}", [
            'revision' => 1,
            'title' => 'Cenário alterado depois da revisão solicitada',
            'description' => null, 'source_party_id' => null,
            'gross_amount_cents' => 10001, 'allocations' => [],
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($reviewerJwt)->getJson("/api/v1/finance/reviews/{$reviewId}")
            ->assertOk()->assertJsonPath('data.is_stale', true);
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Parecer não pode ser emitido em revisão desatualizada.',
        ])->assertConflict();
        $this->withToken($reviewerJwt)->getJson('/api/v1/finance/reviews?stale=1')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", [
            'request_key' => (string) Str::uuid(),
            'scenario_revision' => 2,
            'request_note' => 'Solicito parecer da revisão atualizada com novos números.',
        ])->assertCreated();
        $this->assertDatabaseCount('repasse_scenario_reviews', 2);
    }

    public function test_only_requester_can_withdraw_and_only_authorized_reviewer_can_assign(): void
    {
        [$creator, $jwt] = $this->token();
        [$reviewer, $reviewerJwt] = $this->token([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        $scenario = $this->scenario($jwt);
        [$reviewId] = $this->submit($scenario, $jwt);
        $this->withToken($jwt)->postJson("/api/v1/finance/reviews/{$reviewId}/assign", [
            'revision' => 1, 'assigned_to' => $reviewer->id,
        ])->assertForbidden();
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/assign", [
            'revision' => 1, 'assigned_to' => $reviewer->id,
        ])->assertOk()->assertJsonPath('data.assigned_to', $reviewer->id)
            ->assertJsonPath('data.revision', 2);
        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$reviewId}/withdraw", [
            'revision' => 2, 'note' => 'Retirada não autorizada por um terceiro.',
        ])->assertForbidden();
        $this->withToken($jwt)->postJson("/api/v1/finance/reviews/{$reviewId}/withdraw", [
            'revision' => 2, 'note' => 'Retirada solicitada pelo responsável inicial.',
        ])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->withToken($jwt)->getJson("/api/v1/finance/reviews/{$reviewId}/integrity")
            ->assertOk()->assertJsonPath('data.checked_events', 3);
    }

    public function test_detects_event_tampering_and_restricts_csv(): void
    {
        [$creator, $jwt] = $this->token();
        $scenario = $this->scenario($jwt);
        [$reviewId] = $this->submit($scenario, $jwt);
        $this->withToken($jwt)->get("/api/v1/finance/reviews/{$reviewId}/export")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->withHeaders(['Authorization' => ''])
            ->get("/api/v1/finance/reviews/{$reviewId}/export")->assertUnauthorized();
        DB::table('repasse_scenario_review_events')->where('review_id', $reviewId)
            ->update(['body' => 'ALTERADO FORA DA APLICAÇÃO']);
        $this->withToken($jwt)->getJson("/api/v1/finance/reviews/{$reviewId}/integrity")
            ->assertOk()->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.failed_sequence', 1);
    }
}
