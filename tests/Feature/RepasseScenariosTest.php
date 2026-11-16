<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RepasseScenariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('scenario-test-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(array $permissions = ['cases.manage', 'scenarios.manage']): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create(['slug' => 'sim_'. $user->id, 'name' => 'Planejamento']);
        foreach ($permissions as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function fixture(string $token): array
    {
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Dossiê documental do cenário',
        ])->assertCreated()->json('data.id');
        $source = Party::query()->create(['type' => 'company', 'name' => 'Origem de cenário']);
        $recipient = Party::query()->create(['type' => 'person', 'name' => 'Destinatário de cenário']);
        $other = Party::query()->create(['type' => 'company', 'name' => 'Terceiro não vinculado']);
        foreach ([$source, $recipient] as $party) {
            $this->withToken($token)->postJson("/api/v1/repasse-cases/{$id}/parties", [
                'party_id' => $party->id,
            ])->assertCreated();
        }
        return [$id, $source, $recipient, $other];
    }

    private function draft(int $case, Party $source, Party $recipient): array
    {
        return [
            'case_id' => $case,
            'client_reference' => (string) Str::uuid(),
            'title' => 'Prévia manual de distribuição',
            'description' => 'Valores hipotéticos, sem liquidação.',
            'source_party_id' => $source->id,
            'gross_amount_cents' => 12500,
            'allocations' => [[
                'party_id' => $recipient->id,
                'amount_cents' => 12500,
                'memo' => 'Simulação de recebimento',
            ]],
        ];
    }

    public function test_requires_both_permissions_and_authentication(): void
    {
        $this->getJson('/api/v1/finance/summary')->assertUnauthorized();
        $this->postJson('/api/v1/finance/scenarios', [])->assertUnauthorized();
        $onlyCases = $this->token(['cases.manage']);
        $onlyScenarios = $this->token(['scenarios.manage']);
        $this->withToken($onlyCases)->getJson('/api/v1/finance/scenarios')->assertForbidden();
        $this->withToken($onlyScenarios)->getJson('/api/v1/finance/scenarios')->assertForbidden();
        $this->assertDatabaseCount('repasse_scenarios', 0);
    }

    public function test_balanced_draft_has_no_payment_permissions_or_executable_status(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $result = $this->withToken($token)->postJson('/api/v1/finance/scenarios',
            $this->draft($case, $source, $recipient))
            ->assertCreated()->assertJsonPath('data.balance_state', 'balanced')
            ->assertJsonPath('data.difference_cents', 0)
            ->assertJsonPath('data.currency', 'BRL')
            ->assertJsonPath('data.executable', false)
            ->assertJsonPath('data.payment_enabled', false);
        $this->withToken($token)->getJson('/api/v1/finance/summary')
            ->assertOk()->assertJsonPath('data.balanced_drafts', 1);
        $this->assertDatabaseCount('repasse_scenario_versions', 1);
        $this->assertDatabaseCount('repasse_scenario_allocations', 1);
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $case, 'event_type' => 'scenario_created',
        ]);
        $this->assertArrayNotHasKey('bank_account', $result->json('data'));
    }

    public function test_discrepancies_are_displayed_not_converted_into_real_transfers(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $payload = $this->draft($case, $source, $recipient);
        $payload['allocations'][0]['amount_cents'] = 7500;
        $id = $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertCreated()->assertJsonPath('data.balance_state', 'underallocated')
            ->assertJsonPath('data.difference_cents', 5000)->json('data.id');

        $update = $payload;
        unset($update['case_id'], $update['client_reference']);
        $update['revision'] = 1;
        $update['allocations'][0]['amount_cents'] = 16000;
        $this->withToken($token)->putJson("/api/v1/finance/scenarios/{$id}", $update)
            ->assertOk()->assertJsonPath('data.balance_state', 'overallocated')
            ->assertJsonPath('data.difference_cents', -3500);
        $this->assertDatabaseCount('repasse_scenario_versions', 2);
    }

    public function test_creation_reference_is_idempotent_and_cannot_be_reused_for_other_payload(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $payload = $this->draft($case, $source, $recipient);
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertCreated()->assertJsonPath('created', true);
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertOk()->assertJsonPath('created', false);
        $changed = $payload;
        $changed['gross_amount_cents'] = 13000;
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $changed)->assertConflict();
        $this->assertDatabaseCount('repasse_scenarios', 1);
        $this->assertDatabaseCount('repasse_scenario_versions', 1);
    }

    public function test_revision_conflict_and_unchanged_draft_do_not_duplicate_versions(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $payload = $this->draft($case, $source, $recipient);
        $id = $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertCreated()->json('data.id');
        unset($payload['case_id'], $payload['client_reference']);
        $payload['revision'] = 1;
        $this->withToken($token)->putJson("/api/v1/finance/scenarios/{$id}", $payload)
            ->assertOk()->assertJsonPath('changed', false);
        $payload['title'] = 'Revisão documental';
        $this->withToken($token)->putJson("/api/v1/finance/scenarios/{$id}", $payload)
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->withToken($token)->putJson("/api/v1/finance/scenarios/{$id}", $payload)->assertConflict();
        $this->withToken($token)->getJson("/api/v1/finance/scenarios/{$id}/versions")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson("/api/v1/finance/scenarios/{$id}/compare?left=1&right=2")
            ->assertOk()->assertJsonPath('data.gross_delta_cents', 0);
        $this->assertDatabaseCount('repasse_scenario_versions', 2);
    }

    public function test_rejects_cross_case_inactive_and_unknown_financial_fields(): void
    {
        $token = $this->token();
        [$case, $source, $recipient, $other] = $this->fixture($token);
        $payload = $this->draft($case, $source, $other);
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['allocations.0.party_id']);
        $recipient->is_active = false;
        $recipient->save();
        $payload = $this->draft($case, $source, $recipient);
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertUnprocessable();
        $payload['pix_key'] = 'chave-proibida';
        $payload['commission_percent'] = 7.5;
        $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['pix_key', 'commission_percent']);
        $this->assertDatabaseCount('repasse_scenarios', 0);
    }

    public function test_archival_fork_and_case_lock_preserve_revision_history(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $payload = $this->draft($case, $source, $recipient);
        $id = $this->withToken($token)->postJson('/api/v1/finance/scenarios', $payload)
            ->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/finance/scenarios/{$id}/state", [
            'revision' => 1, 'action' => 'archive',
        ])->assertOk()->assertJsonPath('data.revision', 2);
        $new = $payload;
        unset($new['case_id'], $new['client_reference']);
        $new['revision'] = 2;
        $this->withToken($token)->putJson("/api/v1/finance/scenarios/{$id}", $new)
            ->assertUnprocessable();
        $fork = $this->withToken($token)->postJson("/api/v1/finance/scenarios/{$id}/fork", [
            'revision' => 1, 'client_reference' => (string) Str::uuid(),
            'title' => 'Alternativa à primeira revisão',
        ])->assertCreated()->assertJsonPath('data.forked_from_scenario_id', $id)
            ->assertJsonPath('data.forked_from_revision', 1);
        $forkId = $fork->json('data.id');
        $this->assertNotEquals($id, $forkId);

        $this->withToken($token)->patchJson("/api/v1/repasse-cases/{$case}", [
            'status' => 'archived',
        ])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/finance/scenarios/{$id}/state", [
            'revision' => 2, 'action' => 'reopen',
        ])->assertUnprocessable();
        $this->withToken($token)->getJson("/api/v1/finance/scenarios/{$id}")
            ->assertOk()->assertJsonPath('data.archived_at', DB::table('repasse_scenarios')->find($id)->archived_at);
    }

    public function test_csv_is_private_and_contains_no_execution_instruction(): void
    {
        $token = $this->token();
        [$case, $source, $recipient] = $this->fixture($token);
        $id = $this->withToken($token)->postJson('/api/v1/finance/scenarios',
            $this->draft($case, $source, $recipient))->assertCreated()->json('data.id');
        $this->withToken($token)->get("/api/v1/finance/scenarios/{$id}/export")
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get("/api/v1/finance/scenarios/{$id}/export")->assertUnauthorized();
    }
}
