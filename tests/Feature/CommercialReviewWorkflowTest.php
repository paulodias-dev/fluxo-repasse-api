<?php

namespace Tests\Feature;

use App\Support\TenantDatabase;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommercialReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('commercial-review-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function actor(array $grants): array
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'commercial_review_'.$user->id, 'name' => 'Operador QA']);
        foreach ($grants as $grant) {
            $permission = Permission::query()->firstOrCreate(['key' => $grant]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
        return [$user, $token];
    }

    private function fixture(string $jwt): array
    {
        $case = $this->withToken($jwt)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo revisão comercial',
        ])->assertCreated()->json('data.id');

        $parties = [
            Party::query()->create(['type' => 'company', 'name' => 'Origem QA']),
            Party::query()->create(['type' => 'person', 'name' => 'Destino A QA']),
            Party::query()->create(['type' => 'person', 'name' => 'Destino B QA']),
        ];
        foreach ($parties as $party) {
            $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$case}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        $template = $this->withToken($jwt)->postJson('/api/v1/finance/distribution-templates', [
            'case_id' => $case, 'name' => 'Rateio experimental',
            'description' => null, 'source_party_id' => $parties[0]->id,
            'lines' => [
                ['party_id' => $parties[1]->id, 'basis_points' => 6000, 'memo' => null],
                ['party_id' => $parties[2]->id, 'basis_points' => 4000, 'memo' => null],
            ],
        ])->assertCreated()->json('data.id');

        $draft = [
            'title' => 'Minuta documental de repasse',
            'description' => null, 'template_id' => $template,
            'template_revision' => 1, 'reference_cents' => 120001,
            'conditions' => array_fill_keys(array_keys(CommercialTermsService::FIELDS), null),
            'evidence_ids' => [],
        ];
        $term = $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$case}/commercial-terms", $draft)
            ->assertCreated()->json('data.id');
        return [$case, $term, $template, $draft];
    }

    private function application(int $case, int $term, array $overrides = []): array
    {
        return array_replace([
            'case_id' => $case, 'term_id' => $term,
            'term_revision' => 1, 'request_key' => (string) Str::uuid(),
            'request_note' => 'Solicitar conferência exclusivamente documental.',
        ], $overrides);
    }

    public function test_private_queue_requires_both_permissions(): void
    {
        $url = '/api/v1/commercial-reviews';
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, [])->assertUnauthorized();
        foreach ([[], ['cases.manage'], ['scenarios.manage']] as $grants) {
            [, $token] = $this->actor($grants);
            $this->withToken($token)->getJson($url)->assertForbidden();
            $this->withToken($token)->postJson($url, [])->assertForbidden();
        }
    }

    public function test_complete_request_comment_review_and_hash_chain(): void
    {
        [, $authorToken] = $this->actor(['cases.manage', 'scenarios.manage']);
        [$reviewer, $reviewerToken] = $this->actor([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        [$case, $term] = $this->fixture($authorToken);
        $data = $this->application($case, $term, ['assigned_to' => $reviewer->id]);
        $base = '/api/v1/commercial-reviews';
        $created = $this->withToken($authorToken)->postJson($base, $data)
            ->assertCreated()->assertJsonPath('created', true)
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonPath('data.status', 'pending');
        $review = $created->json('data.id');

        $this->withToken($authorToken)->postJson($base, $data)
            ->assertOk()->assertJsonPath('created', false)
            ->assertJsonPath('data.id', $review);
        $this->withToken($authorToken)->postJson($base, [
            ...$data, 'request_note' => 'Diferente solicitação para a mesma chave.',
        ])->assertConflict();

        $this->withToken($authorToken)->postJson("$base/$review/comment", [
            'body' => 'Campo tributário ainda deve ser esclarecido.',
        ])->assertOk();

        $this->withToken($authorToken)->postJson("$base/$review/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Conferência documental realizada pelo autor.',
        ])->assertForbidden();

        $this->withToken($reviewerToken)->postJson("$base/$review/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Minuta ainda não possui as dez condições preenchidas.',
        ])->assertUnprocessable();

        $this->withToken($reviewerToken)->postJson("$base/$review/decide", [
            'revision' => 1, 'decision' => 'changes_requested',
            'note' => 'Preencher os termos antes de nova submissão.',
        ])->assertOk()->assertJsonPath('data.status', 'changes_requested')
            ->assertJsonPath('data.financial_authorization', false);

        $this->withToken($reviewerToken)->getJson("$base/$review/integrity")
            ->assertOk()->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.checked_events', 3);
        $this->withToken($reviewerToken)->getJson("$base/$review/snapshot")
            ->assertOk()->assertJsonPath('data.term_revision', 1)
            ->assertJsonPath('data.payment_enabled', false);
        $this->assertDatabaseCount('repasse_commercial_reviews', 1);
        $this->assertDatabaseCount('repasse_scenarios', 0);
    }

    public function test_reviewed_decision_needs_full_terms_and_independent_reviewer(): void
    {
        [, $authorToken] = $this->actor(['cases.manage', 'scenarios.manage']);
        [, $reviewerToken] = $this->actor([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        [$case, $term, , $draft] = $this->fixture($authorToken);
        $draft['conditions'] = array_fill_keys(array_keys(CommercialTermsService::FIELDS), 'Descrição explícita de condição comercial');
        $this->withToken($authorToken)->putJson(
            "/api/v1/repasse-cases/{$case}/commercial-terms/{$term}", [...$draft, 'revision' => 1]
        )->assertOk()->assertJsonPath('data.revision', 2);

        $data = $this->application($case, $term, ['term_revision' => 2]);
        $review = $this->withToken($authorToken)->postJson('/api/v1/commercial-reviews', $data)
            ->assertCreated()->json('data.id');
        $this->withToken($reviewerToken)->postJson("/api/v1/commercial-reviews/$review/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Os dez campos da minuta foram conferidos documentalmente.',
        ])->assertOk()->assertJsonPath('data.status', 'reviewed')
            ->assertJsonPath('data.documentary_complete', true)
            ->assertJsonPath('data.payment_enabled', false);
        $this->withToken($authorToken)->postJson('/api/v1/commercial-reviews', [
            ...$data, 'request_key' => (string) Str::uuid(),
        ])->assertConflict();
    }

    public function test_outdated_revision_and_cross_vehicle_request_are_blocked(): void
    {
        [, $authorToken] = $this->actor(['cases.manage', 'scenarios.manage']);
        [, $reviewerToken] = $this->actor([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        [$case, $term, , $draft] = $this->fixture($authorToken);
        [$otherCase] = $this->fixture($authorToken);
        $base = '/api/v1/commercial-reviews';
        $this->withToken($authorToken)->postJson($base, $this->application($otherCase, $term))
            ->assertNotFound();
        $review = $this->withToken($authorToken)->postJson(
            $base, $this->application($case, $term)
        )->assertCreated()->json('data.id');

        $this->withToken($authorToken)->putJson(
            "/api/v1/repasse-cases/{$case}/commercial-terms/{$term}",
            [...$draft, 'title' => 'Minuta atualizada', 'revision' => 1]
        )->assertOk()->assertJsonPath('data.revision', 2);

        $this->withToken($reviewerToken)->getJson("$base/$review")
            ->assertOk()->assertJsonPath('data.is_stale', true);
        $this->withToken($reviewerToken)->postJson("$base/$review/decide", [
            'revision' => 1, 'decision' => 'changes_requested',
            'note' => 'Parecer não pode incidir sobre revisão já alterada.',
        ])->assertConflict();
        $this->withToken($reviewerToken)->getJson("$base/$review/snapshot")
            ->assertOk()->assertJsonPath('data.term_revision', 1);
    }

    public function test_internal_event_tampering_is_detected(): void
    {
        [, $token] = $this->actor(['cases.manage', 'scenarios.manage']);
        [$case, $term] = $this->fixture($token);
        $id = $this->withToken($token)->postJson('/api/v1/commercial-reviews', $this->application($case, $term))
            ->assertCreated()->json('data.id');
        TenantDatabase::table('repasse_commercial_review_events')->where('review_id', $id)
            ->update(['body' => 'conteúdo adulterado']);
        $this->withToken($token)->getJson("/api/v1/commercial-reviews/$id/integrity")
            ->assertOk()->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.failed_sequence', 1);
    }
}
