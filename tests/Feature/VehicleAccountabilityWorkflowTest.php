<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleAccountabilityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('documentary-workflow-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function login(array $grants): array
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'documentary_'.$user->id, 'name' => 'Documento']);
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

    private function vehicle(string $token, string $title = 'Veículo documental'): int
    {
        return $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => $title,
        ])->assertCreated()->json('data.id');
    }

    private function scenario(string $token, int $case, string $title = 'Simulação documental'): int
    {
        return $this->withToken($token)->postJson('/api/v1/finance/scenarios', [
            'case_id' => $case, 'client_reference' => (string) Str::uuid(),
            'title' => $title, 'description' => null,
            'gross_amount_cents' => 10000,
            'source_party_id' => null, 'allocations' => [],
        ])->assertCreated()->json('data.id');
    }

    public function test_read_requires_jwt_cases_and_scenarios_permissions(): void
    {
        $url = '/api/v1/repasse-cases/1/documentary/overview';
        $this->getJson($url)->assertUnauthorized();
        [, $casesOnly] = $this->login(['cases.manage']);
        $this->withToken($casesOnly)->getJson($url)->assertForbidden();
        [, $scenariosOnly] = $this->login(['scenarios.manage']);
        $this->withToken($scenariosOnly)->getJson($url)->assertForbidden();
        [, $both] = $this->login(['cases.manage', 'scenarios.manage']);
        $this->withToken($both)->getJson($url)->assertNotFound();
    }

    public function test_empty_vehicle_has_real_zero_counts_no_payment_authorization(): void
    {
        [, $jwt] = $this->login(['cases.manage', 'scenarios.manage']);
        $id = $this->vehicle($jwt);
        $this->withToken($jwt)->getJson("/api/v1/repasse-cases/{$id}/documentary/overview")
            ->assertOk()
            ->assertJsonPath('data.vehicle.id', $id)
            ->assertJsonPath('data.summary.scenarios_total', 0)
            ->assertJsonPath('data.summary.pending', 0)
            ->assertJsonPath('data.summary.packages_total', 0)
            ->assertJsonPath('data.summary.active_evidence', 0)
            ->assertJsonPath('data.financial_authorization', false)
            ->assertJsonPath('data.payment_enabled', false)
            ->assertJsonCount(0, 'data.scenarios.data');
    }

    public function test_review_lifecycle_moves_from_not_requested_to_pending_to_documentally_reviewed(): void
    {
        [$author, $jwt] = $this->login(['cases.manage', 'scenarios.manage']);
        [$reviewer, $reviewerJwt] = $this->login([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage',
        ]);
        $case = $this->vehicle($jwt);
        $scenario = $this->scenario($jwt, $case);
        $url = "/api/v1/repasse-cases/{$case}/documentary/overview";

        $this->withToken($jwt)->getJson($url)
            ->assertOk()->assertJsonPath('data.summary.not_requested', 1)
            ->assertJsonPath('data.scenarios.data.0.difference_cents', 10000)
            ->assertJsonPath('data.scenarios.data.0.review_state', 'not_requested');

        $review = $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", [
            'request_key' => (string) Str::uuid(), 'scenario_revision' => 1,
            'request_note' => 'Solicito conferência documental desta proposta.',
            'assigned_to' => $reviewer->id,
        ])->assertCreated()->json('data.id');

        $this->withToken($jwt)->getJson($url.'?review_state=pending')
            ->assertOk()->assertJsonCount(1, 'data.scenarios.data')
            ->assertJsonPath('data.scenarios.data.0.last_review_id', $review)
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.summary.not_requested', 0);

        $this->withToken($reviewerJwt)->postJson("/api/v1/finance/reviews/{$review}/decide", [
            'revision' => 1, 'decision' => 'reviewed',
            'note' => 'Documentação conferida, sem autorização de pagamento.',
        ])->assertOk();

        $this->withToken($jwt)->getJson($url.'?review_state=reviewed')
            ->assertOk()->assertJsonCount(1, 'data.scenarios.data')
            ->assertJsonPath('data.scenarios.data.0.review_state', 'reviewed')
            ->assertJsonPath('data.summary.reviewed', 1)
            ->assertJsonPath('data.payment_enabled', false);
        $this->assertNotEquals($author->id, $reviewer->id);
    }

    public function test_scenario_revision_makes_previous_review_stale_without_losing_history(): void
    {
        [, $jwt] = $this->login(['cases.manage', 'scenarios.manage']);
        $case = $this->vehicle($jwt);
        $scenario = $this->scenario($jwt, $case);
        $review = $this->withToken($jwt)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", [
            'request_key' => (string) Str::uuid(), 'scenario_revision' => 1,
            'request_note' => 'Conferir dados documentais do veículo.',
        ])->assertCreated()->json('data.id');

        $this->withToken($jwt)->putJson("/api/v1/finance/scenarios/{$scenario}", [
            'revision' => 1,
            'title' => 'Nova versão de simulação documental',
            'description' => null, 'source_party_id' => null,
            'gross_amount_cents' => 12000,
            'allocations' => [],
        ])->assertOk();

        $url = "/api/v1/repasse-cases/{$case}/documentary/overview";
        $this->withToken($jwt)->getJson($url.'?review_state=stale')
            ->assertOk()
            ->assertJsonCount(1, 'data.scenarios.data')
            ->assertJsonPath('data.scenarios.data.0.last_review_id', $review)
            ->assertJsonPath('data.scenarios.data.0.review_state', 'stale')
            ->assertJsonPath('data.summary.stale', 1);
        $this->withToken($jwt)->getJson("/api/v1/finance/reviews/{$review}")
            ->assertOk()->assertJsonPath('data.is_stale', true);
    }

    public function test_archived_case_is_readable_but_never_appears_ready_for_new_reviews(): void
    {
        [, $jwt] = $this->login(['cases.manage', 'scenarios.manage']);
        $case = $this->vehicle($jwt);
        $this->scenario($jwt, $case);
        $this->withToken($jwt)->patchJson("/api/v1/repasse-cases/{$case}", [
            'status' => 'archived',
        ])->assertOk();

        $url = "/api/v1/repasse-cases/{$case}/documentary/overview";
        $this->withToken($jwt)->getJson($url)
            ->assertOk()->assertJsonPath('data.vehicle.status', 'archived')
            ->assertJsonPath('data.summary.archived', 1)
            ->assertJsonPath('data.scenarios.data.0.review_state', 'archived');
        $this->withToken($jwt)->getJson($url.'?review_state=not_requested')
            ->assertOk()->assertJsonCount(0, 'data.scenarios.data');
    }

    public function test_real_package_is_counted_and_cross_vehicle_rows_are_excluded(): void
    {
        [, $jwt] = $this->login([
            'cases.manage', 'scenarios.manage', 'packages.manage',
        ]);
        $one = $this->vehicle($jwt);
        $two = $this->vehicle($jwt, 'Outro veículo');
        $scenario = $this->scenario($jwt, $one);
        $this->scenario($jwt, $two, 'Simulação não relacionada');
        $base = "/api/v1/repasse-cases/{$one}/packages";
        $selection = [
            'title' => 'Pacote auditável do veículo', 'note' => null,
            'evidence_ids' => [], 'scenario_ids' => [$scenario], 'review_ids' => [],
        ];
        $hash = $this->withToken($jwt)->postJson("$base/preview", $selection)
            ->assertOk()->json('data.manifest_sha256');
        $package = $this->withToken($jwt)->postJson($base, $selection + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $hash,
        ])->assertCreated()->json('data.id');

        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$one}/documentary/overview"
        )->assertOk()
            ->assertJsonPath('data.summary.scenarios_total', 1)
            ->assertJsonPath('data.summary.packages_total', 1)
            ->assertJsonPath('data.latest_package.id', $package)
            ->assertJsonPath('data.financial_authorization', false);
        $this->withToken($jwt)->getJson(
            "/api/v1/repasse-cases/{$two}/documentary/overview"
        )->assertOk()
            ->assertJsonPath('data.summary.scenarios_total', 1)
            ->assertJsonPath('data.summary.packages_total', 0);
    }

    public function test_validation_search_escape_and_stable_pagination(): void
    {
        [, $jwt] = $this->login(['cases.manage', 'scenarios.manage']);
        $case = $this->vehicle($jwt);
        for ($i = 1; $i <= 22; $i++) {
            $this->scenario($jwt, $case, "Simulação número $i");
        }
        $base = "/api/v1/repasse-cases/{$case}/documentary/overview";
        $this->withToken($jwt)->getJson("$base?page=2")
            ->assertOk()->assertJsonCount(2, 'data.scenarios.data')
            ->assertJsonPath('data.scenarios.total', 22)
            ->assertJsonPath('data.scenarios.current_page', 2);
        $this->withToken($jwt)->getJson("$base?search=Simula%C3%A7%C3%A3o")
            ->assertOk()->assertJsonPath('data.scenarios.total', 22);
        $this->withToken($jwt)->getJson("$base?search=%25")
            ->assertOk()->assertJsonPath('data.scenarios.total', 0);
        $this->withToken($jwt)->getJson("$base?review_state=paid")
            ->assertUnprocessable()->assertJsonValidationErrors('review_state');
        $this->withToken($jwt)->getJson("$base?status=deleted")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->withToken($jwt)->getJson("$base?page=-1")
            ->assertUnprocessable()->assertJsonValidationErrors('page');
    }
}
