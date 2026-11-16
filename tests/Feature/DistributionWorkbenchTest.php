<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DistributionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DistributionWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('distribution-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(array $grants = ['cases.manage', 'scenarios.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'distribution_'. $user->id, 'name' => 'Operador']);
        foreach ($grants as $grant) {
            $permission = Permission::query()->firstOrCreate(['key' => $grant]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function fixture(string $token): array
    {
        $case = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Dossiê para modelos',
        ])->assertCreated()->json('data.id');
        $source = Party::query()->create(['type' => 'company', 'name' => 'Origem fictícia QA']);
        $a = Party::query()->create(['type' => 'person', 'name' => 'Primeiro cadastro QA']);
        $b = Party::query()->create(['type' => 'person', 'name' => 'Segundo cadastro QA']);
        $outsider = Party::query()->create(['type' => 'company', 'name' => 'Cadastro fora do dossiê QA']);
        foreach ([$source, $a, $b] as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$case}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        return [$case, $source, $a, $b, $outsider];
    }

    private function payload(int $case, Party $source, Party $a, Party $b): array
    {
        return [
            'case_id' => $case,
            'name' => 'Modelo definido por operador',
            'description' => null,
            'source_party_id' => $source->id,
            'lines' => [
                ['party_id' => $a->id, 'basis_points' => 6000, 'memo' => 'Distribuição manual 60%'],
                ['party_id' => $b->id, 'basis_points' => 4000, 'memo' => 'Distribuição manual 40%'],
            ],
        ];
    }

    private function createTemplate(string $token, array $payload): int
    {
        return $this->withToken($token)->postJson('/api/v1/finance/distribution-templates', $payload)
            ->assertCreated()->assertJsonPath('data.revision', 1)->json('data.id');
    }

    public function test_only_users_with_both_permissions_can_access_every_new_route(): void
    {
        $this->getJson('/api/v1/finance/distribution-templates')->assertUnauthorized();
        $this->getJson('/api/v1/finance/distribution-batches')->assertUnauthorized();
        foreach ([['cases.manage'], ['scenarios.manage'], []] as $grants) {
            $token = $this->token($grants);
            $this->withToken($token)->getJson('/api/v1/finance/distribution-templates')->assertForbidden();
            $this->withToken($token)->getJson('/api/v1/finance/distribution-batches')->assertForbidden();
            $this->withToken($token)->postJson('/api/v1/finance/distribution-templates', [])->assertForbidden();
        }
    }

    public function test_calculator_uses_largest_remainder_without_floats(): void
    {
        $calculator = app(DistributionCalculator::class);
        $lines = [
            ['party_id' => 3, 'basis_points' => 3334],
            ['party_id' => 1, 'basis_points' => 3333],
            ['party_id' => 2, 'basis_points' => 3333],
        ];
        $result = $calculator->calculate(10001, $lines);
        $this->assertSame([3333, 3333, 3335], array_column($result, 'amount_cents'));
        $this->assertSame([1, 2, 3], array_column($result, 'party_id'));
        $this->assertSame(10001, array_sum(array_column($result, 'amount_cents')));
        $this->assertSame($result, $calculator->calculate(10001, array_reverse($lines)));
        $this->expectException(ValidationException::class);
        $calculator->calculate(1, [['party_id' => 1, 'basis_points' => 5000],
            ['party_id' => 2, 'basis_points' => 5000]]);
    }

    public function test_template_creation_versions_preview_and_fork(): void
    {
        $token = $this->token();
        [$case, $source, $a, $b] = $this->fixture($token);
        $payload = $this->payload($case, $source, $a, $b);
        $id = $this->createTemplate($token, $payload);
        $url = "/api/v1/finance/distribution-templates/{$id}";
        $this->withToken($token)->postJson("$url/preview", [
            'template_revision' => 1, 'gross_amount_cents' => 101,
        ])->assertOk()->assertJsonPath('data.allocated_cents', 101)
            ->assertJsonPath('data.difference_cents', 0)
            ->assertJsonPath('data.payment_enabled', false);
        $payload['revision'] = 1;
        unset($payload['case_id']);
        $payload['lines'][0]['basis_points'] = 7000;
        $payload['lines'][1]['basis_points'] = 3000;
        $this->withToken($token)->putJson($url, $payload)
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($token)->putJson($url, $payload)->assertConflict();
        $this->withToken($token)->getJson("$url/versions")->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("$url/versions/1")->assertOk()
            ->assertJsonPath('data.snapshot.lines.0.basis_points', 6000);
        $fork = $this->withToken($token)->postJson("$url/fork", [
            'revision' => 1, 'name' => 'Alternativa ao modelo inicial',
        ])->assertCreated()->assertJsonPath('data.forked_from_template_id', $id)
            ->assertJsonPath('data.forked_from_revision', 1);
        $this->assertNotSame($id, $fork->json('data.id'));
        $this->assertDatabaseCount('repasse_distribution_versions', 3);
    }

    public function test_batch_is_previewed_then_committed_atomically_and_idempotently(): void
    {
        $token = $this->token();
        [$case, $source, $a, $b] = $this->fixture($token);
        $id = $this->createTemplate($token, $this->payload($case, $source, $a, $b));
        $url = "/api/v1/finance/distribution-templates/{$id}";
        $input = [
            'template_revision' => 1,
            'request_key' => (string) Str::uuid(),
            'rows' => [
                ['client_reference' => (string) Str::uuid(), 'title' => 'Primeira prévia', 'gross_amount_cents' => 101],
                ['client_reference' => (string) Str::uuid(), 'title' => 'Segunda prévia', 'gross_amount_cents' => 200],
            ],
        ];
        $this->withToken($token)->postJson("$url/batch-preview", $input)
            ->assertOk()->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.difference_cents', 0)
            ->assertJsonPath('data.rows.1.payment_enabled', false);
        $this->assertDatabaseCount('repasse_scenarios', 0);
        $batch = $this->withToken($token)->postJson("$url/batches", $input)
            ->assertCreated()->assertJsonPath('created', true)
            ->assertJsonCount(2, 'batch.results')->json('batch.id');
        $this->assertDatabaseCount('repasse_scenarios', 2);
        $this->assertDatabaseCount('repasse_distribution_batches', 1);
        $this->withToken($token)->postJson("$url/batches", $input)
            ->assertOk()->assertJsonPath('created', false);
        $this->assertDatabaseCount('repasse_scenarios', 2);
        $changed = $input;
        $changed['rows'][0]['gross_amount_cents']++;
        $this->withToken($token)->postJson("$url/batches", $changed)->assertConflict();
        $this->withToken($token)->getJson("/api/v1/finance/distribution-batches/{$batch}")
            ->assertOk()->assertJsonCount(2, 'data.results');
    }

    public function test_batch_is_rolled_back_on_invalid_participant_or_reference(): void
    {
        $token = $this->token();
        [$case, $source, $a, $b, $outsider] = $this->fixture($token);
        $payload = $this->payload($case, $source, $a, $b);
        $payload['lines'][0]['party_id'] = $outsider->id;
        $this->withToken($token)->postJson('/api/v1/finance/distribution-templates', $payload)
            ->assertUnprocessable();
        $id = $this->createTemplate($token, $this->payload($case, $source, $a, $b));
        $input = [
            'template_revision' => 1,
            'request_key' => (string) Str::uuid(),
            'rows' => [
                ['client_reference' => (string) Str::uuid(), 'title' => 'OK', 'gross_amount_cents' => 300],
                ['client_reference' => (string) Str::uuid(), 'title' => 'Inválida', 'gross_amount_cents' => 1],
            ],
        ];
        $this->withToken($token)->postJson("/api/v1/finance/distribution-templates/{$id}/batches", $input)
            ->assertUnprocessable();
        $this->assertDatabaseCount('repasse_scenarios', 0);
        $this->assertDatabaseCount('repasse_distribution_batches', 0);
        $this->assertDatabaseCount('repasse_scenario_versions', 0);
    }

    public function test_archived_cases_or_templates_never_create_scenarios(): void
    {
        $token = $this->token();
        [$case, $source, $a, $b] = $this->fixture($token);
        $id = $this->createTemplate($token, $this->payload($case, $source, $a, $b));
        $url = "/api/v1/finance/distribution-templates/{$id}";
        $this->withToken($token)->patchJson("$url/state", [
            'revision' => 1, 'action' => 'archive',
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($token)->postJson("$url/preview", [
            'template_revision' => 2, 'gross_amount_cents' => 500,
        ])->assertUnprocessable();
        $this->withToken($token)->patchJson("$url/state", [
            'revision' => 2, 'action' => 'reopen',
        ])->assertOk()->assertJsonPath('data.revision', 3);
        $this->withToken($token)->patchJson("/api/v1/repasse-cases/{$case}", [
            'status' => 'archived',
        ])->assertOk();
        $this->withToken($token)->postJson("$url/preview", [
            'template_revision' => 3, 'gross_amount_cents' => 500,
        ])->assertUnprocessable();
    }

    public function test_batch_csv_requires_authentication_and_contains_no_bank_order(): void
    {
        $token = $this->token();
        [$case, $source, $a, $b] = $this->fixture($token);
        $id = $this->createTemplate($token, $this->payload($case, $source, $a, $b));
        $input = [
            'template_revision' => 1,
            'request_key' => (string) Str::uuid(),
            'rows' => [[
                'client_reference' => (string) Str::uuid(), 'title' => '=INJECTED',
                'gross_amount_cents' => 500,
            ]],
        ];
        $batch = $this->withToken($token)->postJson(
            "/api/v1/finance/distribution-templates/{$id}/batches", $input
        )->assertCreated()->json('batch.id');
        $this->withToken($token)->get("/api/v1/finance/distribution-batches/{$batch}/export")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->withHeaders(['Authorization' => ''])
            ->get("/api/v1/finance/distribution-batches/{$batch}/export")->assertUnauthorized();
    }
}
