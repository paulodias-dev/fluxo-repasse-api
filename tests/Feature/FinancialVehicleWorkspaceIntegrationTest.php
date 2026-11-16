<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinancialVehicleWorkspaceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('vehicle-integrated-qa-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function jwt(array $grants): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'vehicle_fin_'.$user->id, 'name' => 'Planejamento']);
        foreach ($grants as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_vehicle_profile_scenarios_models_reviews_and_private_package_share_one_case(): void
    {
        $token = $this->jwt([
            'cases.manage', 'scenarios.manage', 'scenario_reviews.manage', 'packages.manage',
        ]);
        $vehicle = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo compartilhado na operação', 'brand' => 'Toyota',
            'plate' => 'AAA1B23', 'purchase_amount_cents' => 1230000,
            'sale_amount_cents' => 1700000,
        ])->assertCreated()->assertJsonPath('data.plate', 'AAA1B23')->json('data.id');
        $this->withToken($token)->getJson('/api/v1/repasse-cases?search=AAA1B23')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $vehicle);

        $source = Party::query()->create(['type' => 'company', 'name' => 'Fornecedor QA']);
        $recipient = Party::query()->create(['type' => 'person', 'name' => 'Destinatário QA']);
        foreach ([$source, $recipient] as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$vehicle}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }

        $scenario = $this->withToken($token)->postJson('/api/v1/finance/scenarios', [
            'case_id' => $vehicle, 'client_reference' => (string) Str::uuid(),
            'title' => 'Distribuição hipotética', 'description' => null,
            'gross_amount_cents' => 15000, 'source_party_id' => $source->id,
            'allocations' => [['party_id' => $recipient->id, 'amount_cents' => 15000]],
        ])->assertCreated()->assertJsonPath('data.balance_state', 'balanced')
            ->assertJsonPath('data.payment_enabled', false)->json('data.id');

        $template = $this->withToken($token)->postJson('/api/v1/finance/distribution-templates', [
            'case_id' => $vehicle, 'name' => 'Modelo manual de veículo',
            'description' => null, 'source_party_id' => $source->id,
            'lines' => [['party_id' => $recipient->id, 'basis_points' => 10000]],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/v1/finance/distribution-templates/{$template}/preview", [
            'template_revision' => 1, 'gross_amount_cents' => 15000,
        ])->assertOk()->assertJsonPath('data.difference_cents', 0)
            ->assertJsonPath('data.payment_enabled', false);

        $batch = [
            'template_revision' => 1, 'request_key' => (string) Str::uuid(),
            'rows' => [[
                'client_reference' => (string) Str::uuid(), 'title' => 'Proposta adicional',
                'gross_amount_cents' => 9000, 'description' => null,
            ]],
        ];
        $this->withToken($token)->postJson("/api/v1/finance/distribution-templates/{$template}/batch-preview", $batch)
            ->assertOk()->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.payment_enabled', false);
        $this->withToken($token)->postJson("/api/v1/finance/distribution-templates/{$template}/batches", $batch)
            ->assertCreated()->assertJsonPath('batch.payment_enabled', false);
        $this->withToken($token)->getJson("/api/v1/finance/scenarios?case_id={$vehicle}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("/api/v1/finance/distribution-templates?case_id={$vehicle}")
            ->assertOk()->assertJsonCount(1, 'data');

        $review = $this->withToken($token)->postJson("/api/v1/finance/scenarios/{$scenario}/reviews", [
            'request_key' => (string) Str::uuid(), 'scenario_revision' => 1,
            'request_note' => 'Solicito revisão exclusivamente documental desta simulação.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->withToken($token)->getJson("/api/v1/finance/reviews?case_id={$vehicle}")
            ->assertOk()->assertJsonCount(1, 'data');

        $base = "/api/v1/repasse-cases/{$vehicle}/packages";
        $selection = [
            'title' => 'Pacote de verificação', 'note' => 'Sem movimentação financeira',
            'evidence_ids' => [], 'scenario_ids' => [$scenario], 'review_ids' => [$review],
        ];
        $preview = $this->withToken($token)->postJson("$base/preview", $selection)
            ->assertOk()->assertJsonPath('data.can_pay', false)
            ->json('data.manifest_sha256');
        $package = $this->withToken($token)->postJson($base, $selection + [
            'request_key' => (string) Str::uuid(), 'expected_manifest_sha256' => $preview,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->getJson("$base/{$package}/integrity")
            ->assertOk()->assertJsonPath('data.manifest_integrity', true)
            ->assertJsonPath('data.evidence_integrity', true);
        $this->assertDatabaseCount('repasse_case_packages', 1);
    }

    public function test_reading_a_vehicle_does_not_grant_financial_review_or_package_access(): void
    {
        $token = $this->jwt(['cases.manage']);
        $vehicle = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo somente operacional',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->getJson("/api/v1/repasse-cases/{$vehicle}")
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/finance/scenarios?case_id='.$vehicle)
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/finance/reviews?case_id='.$vehicle)
            ->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/repasse-cases/{$vehicle}/packages")
            ->assertForbidden();
        $this->assertDatabaseCount('repasse_scenarios', 0);
        $this->assertDatabaseCount('repasse_case_packages', 0);
    }
}
