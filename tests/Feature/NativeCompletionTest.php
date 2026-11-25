<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\NativePushDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NativeCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function account(bool $allowed = true): array
    {
        config()->set('jwt.secret', str_repeat('native-completion-test-', 4));
        request()->attributes->remove('verified_tenant_id');
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(['tenant_id' => $user->tenant_id, 'slug' => 'native_qa'], ['name' => 'Operador']);
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Strong-Passphrase-123!'])
            ->assertOk()->json('access_token');
        return [$user, $token];
    }

    public function test_categories_are_scoped_and_ignore_planned_and_voided_rows(): void
    {
        [$a, $jwtA] = $this->account();
        [$b, $jwtB] = $this->account();
        foreach ([[$a, $jwtA, 1234], [$b, $jwtB, 9876]] as [$user, $jwt, $amount]) {
            $id = $this->withToken($jwt)->postJson('/api/v1/repasse-cases', ['title' => 'Veículo QA'])
                ->assertCreated()->json('data.id');
            foreach ([['realized', $amount, null], ['planned', 400, null], ['realized', 500, now()]] as [$status, $value, $voided]) {
                DB::table('repasse_case_financial_entries')->insert([
                    'tenant_id' => $user->tenant_id, 'repasse_case_id' => $id, 'kind' => 'expense',
                    'status' => $status, 'category' => 'Oficina', 'description' => 'Teste',
                    'amount_cents' => $value, 'effective_on' => '2026-01-01',
                    'voided_at' => $voided, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        $this->withToken($jwtA)->getJson('/api/v1/operations/expense-categories?from=2026-01-01&to=2026-01-31')
            ->assertOk()->assertJsonPath('data.total_cents', 1234)->assertJsonCount(1, 'data.categories');
        $this->withToken($jwtA)->getJson('/api/v1/operations/expense-categories?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable();
        [, $denied] = $this->account(false);
        $this->withToken($denied)->getJson('/api/v1/operations/expense-categories?from=2026-01-01&to=2026-01-31')->assertForbidden();
    }

    public function test_push_is_opt_in_private_and_revocable_by_owner_only(): void
    {
        config()->set('services.expo_push.enabled', true);
        [$a, $jwtA] = $this->account();
        [, $jwtB] = $this->account();
        $token = 'ExpoPushToken[qa_fixture_only]';
        $this->withToken($jwtA)->putJson('/api/v1/connections/devices', ['token' => $token])->assertOk();
        $row = DB::table('native_push_devices')->first();
        $this->assertNotSame($token, $row->token_ciphertext);
        $this->withToken($jwtB)->deleteJson('/api/v1/connections/devices', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('native_push_devices', 1);
        DB::table('collab_notifications')->insert(['recipient_id' => $a->id, 'type' => 'new_message',
            'data' => json_encode(['body' => 'private text']), 'created_at' => now()]);
        Http::fake(['https://exp.host/*' => Http::response(['data' => ['status' => 'ok']], 200)]);
        $this->assertSame(1, app(NativePushDelivery::class)->run()['accepted']);
        Http::assertSent(fn ($request) => $request['to'] === $token && ! str_contains($request->body(), 'private text'));
        $this->assertSame(0, app(NativePushDelivery::class)->run()['attempted']);
        $this->withToken($jwtA)->deleteJson('/api/v1/connections/devices', ['token' => $token])->assertOk();
        $this->assertDatabaseCount('native_push_devices', 0);
    }
}
