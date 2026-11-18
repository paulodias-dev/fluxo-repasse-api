<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManualSettlementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('approved-settlement-test-', 4));
        config()->set('jwt.blacklist_enabled', true);
        Storage::fake('local');
    }

    private function token(bool $approve = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        $role = Role::query()->create([
            'slug' => 'finance_role_'.$user->id, 'name' => 'Operador financeiro',
        ]);
        foreach (['cases.manage', 'parties.manage', 'financial.manage'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        if ($approve) {
            $permission = Permission::query()->firstOrCreate(['key' => 'financial.approve']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    private function vehicle(string $jwt, bool $sold = true): array
    {
        $values = [
            'title' => 'Veículo financiado QA',
            'purchased_on' => '2026-11-01', 'purchase_amount_cents' => 2000000,
        ];
        if ($sold) {
            $values['sold_on'] = '2026-11-16';
            $values['sale_amount_cents'] = 2500000;
        }
        $id = $this->withToken($jwt)->postJson('/api/v1/repasse-cases', $values)
            ->assertCreated()->json('data.id');
        $payer = Party::query()->create(['type' => 'company', 'name' => 'Pagador legítimo']);
        $first = Party::query()->create(['type' => 'person', 'name' => 'Sócio']);
        $second = Party::query()->create(['type' => 'person', 'name' => 'Intermediador']);
        foreach ([$payer, $first, $second] as $p) {
            $this->withToken($jwt)->postJson("/api/v1/repasse-cases/{$id}/parties", [
                'party_id' => $p->id,
            ])->assertCreated();
        }
        return [$id, $payer, $first, $second];
    }

    private function fixed(int $payer, int $share, int $commission): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'payer_party_id' => $payer,
            'gross_amount_cents' => 100000,
            'mode' => 'fixed',
            'deductions' => [[
                'kind' => 'tax', 'description' => 'Imposto informado',
                'amount_cents' => 10000,
            ]],
            'lines' => [
                ['party_id' => $share, 'kind' => 'share', 'amount_cents' => 60000],
                ['party_id' => $commission, 'kind' => 'commission', 'amount_cents' => 30000],
            ],
        ];
    }

    public function test_fixed_plan_approval_partial_payments_optional_proof_and_void(): void
    {
        $jwt = $this->token();
        [$case, $payer, $share, $commission] = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/{$case}/settlements";
        $draft = $this->fixed($payer->id, $share->id, $commission->id);
        $this->withToken($jwt)->getJson("{$url}/options")->assertOk()
            ->assertJsonPath('data.sale_registered', true)
            ->assertJsonCount(3, 'data.parties');
        $created = $this->withToken($jwt)->postJson($url, $draft)
            ->assertCreated()->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.snapshot.net_amount_cents', 90000)
            ->assertJsonPath('data.snapshot.deduction_total_cents', 10000)
            ->assertJsonPath('data.obligation_created', false)
            ->assertJsonPath('data.payment_executed', false);
        $plan = $created->json('data.id');
        $sha = $created->json('data.snapshot_sha256');
        $this->withToken($jwt)->postJson("{$url}/{$plan}/payments", [
            'request_key' => (string) Str::uuid(),
            'beneficiary_party_id' => $share->id,
            'amount_cents' => 20000, 'paid_on' => '2026-11-17',
            'confirmed_external' => true,
        ])->assertUnprocessable();
        $approved = $this->withToken($jwt)->postJson("{$url}/{$plan}/approve", [
            'expected_snapshot_sha256' => $sha,
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.obligation_created', true)
            ->assertJsonPath('data.approved_by', $created->json('data.created_by'));
        $this->withToken($jwt)->postJson("{$url}/{$plan}/approve", [
            'expected_snapshot_sha256' => $sha,
        ])->assertOk();

        $payload = [
            'request_key' => (string) Str::uuid(),
            'beneficiary_party_id' => $share->id,
            'amount_cents' => 20000, 'paid_on' => '2026-11-17',
            'confirmed_external' => true,
        ];
        $reported = $this->withToken($jwt)->postJson("{$url}/{$plan}/payments", $payload)
            ->assertCreated()->assertJsonPath('data.reported_total_cents', 20000)
            ->assertJsonPath('data.remaining_total_cents', 70000)
            ->assertJsonPath('data.payments.0.proof_sha256', null)
            ->assertJsonPath('data.payments.0.bank_confirmed', false);
        $payment = $reported->json('data.payments.0.id');
        $this->withToken($jwt)->postJson("{$url}/{$plan}/payments", $payload)
            ->assertCreated()->assertJsonCount(1, 'data.payments');
        $this->withToken($jwt)->postJson("{$url}/{$plan}/payments", [
            ...$payload, 'amount_cents' => 21000,
        ])->assertConflict();
        $this->withToken($jwt)->postJson("{$url}/{$plan}/payments", [
            ...$payload, 'request_key' => (string) Str::uuid(),
            'amount_cents' => 50000,
        ])->assertUnprocessable()->assertJsonValidationErrors('amount_cents');

        $this->withToken($jwt)->post("{$url}/{$plan}/payments/{$payment}/proof", [
            'proof' => UploadedFile::fake()->create('comprovante.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $p = DB::table('repasse_plan_payments')->find($payment);
        Storage::disk('local')->assertExists($p->proof_path);
        $this->withToken($jwt)->get("{$url}/{$plan}/payments/{$payment}/proof")
            ->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->withToken($jwt)->postJson("{$url}/{$plan}/payments/{$payment}/void", [
            'reason' => 'Retificação de valor informado incorretamente',
        ])->assertOk()->assertJsonPath('data.reported_total_cents', 0)
            ->assertJsonPath('data.remaining_total_cents', 90000);
        $this->withToken($jwt)->postJson("{$url}/{$plan}/cancel", [
            'reason' => 'Operação cancelada conforme decisão',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.obligation_created', false);
        $this->assertDatabaseCount('repasse_plan_payments', 1);
        $this->assertDatabaseCount('repasse_case_financial_entries', 0);
    }

    public function test_percentage_rounds_cents_deterministically_and_rejects_invalid_totals(): void
    {
        $jwt = $this->token();
        [$case, $payer, $a, $b] = $this->vehicle($jwt);
        $url = "/api/v1/repasse-cases/{$case}/settlements";
        $payload = [
            'request_key' => (string) Str::uuid(),
            'payer_party_id' => $payer->id,
            'gross_amount_cents' => 10001,
            'mode' => 'percentage', 'deductions' => [],
            'lines' => [
                ['party_id' => $a->id, 'kind' => 'share', 'basis_points' => 6000],
                ['party_id' => $b->id, 'kind' => 'commission', 'basis_points' => 4000],
            ],
        ];
        $this->withToken($jwt)->postJson($url, [
            ...$payload, 'lines' => [
                ['party_id' => $a->id, 'kind' => 'share', 'basis_points' => 6000],
                ['party_id' => $b->id, 'kind' => 'commission', 'basis_points' => 3999],
            ],
        ])->assertUnprocessable();
        $this->withToken($jwt)->postJson($url, $payload)
            ->assertCreated()
            ->assertJsonPath('data.snapshot.net_amount_cents', 10001)
            ->assertJsonPath('data.snapshot.lines.0.amount_cents', 6001)
            ->assertJsonPath('data.snapshot.lines.1.amount_cents', 4000)
            ->assertJsonPath('data.payment_executed', false);
    }

    public function test_cannot_create_financial_obligation_without_real_sale_or_valid_participants(): void
    {
        $jwt = $this->token();
        [$case, $payer, $share, $other] = $this->vehicle($jwt, false);
        $url = "/api/v1/repasse-cases/{$case}/settlements";
        $draft = $this->fixed($payer->id, $share->id, $other->id);
        $this->withToken($jwt)->postJson($url, $draft)
            ->assertUnprocessable()->assertJsonValidationErrors('case');
        $this->withToken($jwt)->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($jwt)->postJson($url, [
            ...$draft, 'payment_enabled' => true,
        ])->assertUnprocessable();
    }

    public function test_only_finance_approver_can_approve_even_when_creator_has_manage_permission(): void
    {
        $owner = $this->token();
        [$case, $payer, $share, $commission] = $this->vehicle($owner);
        $url = "/api/v1/repasse-cases/{$case}/settlements";
        $plan = $this->withToken($owner)->postJson($url,
            $this->fixed($payer->id, $share->id, $commission->id)
        )->assertCreated()->json('data');
        $operator = $this->token(false);
        $this->withToken($operator)->postJson("{$url}/{$plan['id']}/approve", [
            'expected_snapshot_sha256' => $plan['snapshot_sha256'],
        ])->assertForbidden();
        // Mesma pessoa que preparou pode aprovar, se possuir a permissão.
        $this->withToken($owner)->postJson("{$url}/{$plan['id']}/approve", [
            'expected_snapshot_sha256' => $plan['snapshot_sha256'],
        ])->assertOk()->assertJsonPath('data.status', 'approved');
    }
}
