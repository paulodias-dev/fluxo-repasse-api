<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Vehicle360Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('vehicle-360-suite-secret-', 4));
        config()->set('jwt.blacklist_enabled', true);
    }

    private function token(bool $allowed = true): string
    {
        $user = User::factory()->create(['password' => 'Strong-Passphrase-123!']);
        if ($allowed) {
            $role = Role::query()->firstOrCreate(
                ['slug' => 'vehicle_operator'], ['name' => 'Operador de veículos']
            );
            $permission = Permission::query()->firstOrCreate(['key' => 'cases.manage']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Strong-Passphrase-123!',
        ])->assertOk()->json('access_token');
    }

    public function test_vehicle_360_profile_and_precise_operational_totals(): void
    {
        $token = $this->token();
        $url = '/api/v1/repasse-cases';
        $id = $this->withToken($token)->postJson($url, [
            'title' => 'Veículo teste', 'plate' => 'ABC1D23',
            'brand' => 'Toyota', 'model' => 'Corolla', 'model_year' => 2023,
            'purchase_location' => 'Leilão', 'seller_name' => 'Fornecedor',
            'purchased_on' => '2026-11-01', 'delivered_on' => '2026-11-15',
            'purchase_amount_cents' => 5000000, 'sale_amount_cents' => 6500000,
        ])->assertCreated()->assertJsonPath('data.plate', 'ABC1D23')
            ->assertJsonPath('data.purchase_amount_cents', 5000000)
            ->json('data.id');

        $base = "$url/$id";
        $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'realized', 'category' => 'manutencao',
            'description' => 'Troca de pneus', 'amount_cents' => 130000,
        ])->assertCreated();
        $planned = $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'transporte',
            'description' => 'Entrega futura', 'amount_cents' => 70000,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'income', 'status' => 'realized', 'category' => 'extra',
            'description' => 'Receita acessória', 'amount_cents' => 20000,
        ])->assertCreated();

        $this->withToken($token)->getJson("$base/finance/summary")
            ->assertOk()
            ->assertJsonPath('data.current_cost_cents', 5130000)
            ->assertJsonPath('data.projected_cost_cents', 5200000)
            ->assertJsonPath('data.projected_result_cents', 1320000);

        $this->withToken($token)->postJson("$base/finance/entries/$planned/void", [])
            ->assertOk()->assertJsonPath('data.voided_at', fn ($value) => is_string($value));
        $this->withToken($token)->getJson("$base/finance/summary")
            ->assertOk()->assertJsonPath('data.projected_cost_cents', 5130000)
            ->assertJsonPath('data.projected_result_cents', 1390000);

        $this->withToken($token)->patchJson($base, [
            'buyer_name' => 'Cliente de teste', 'sold_on' => '2026-11-16',
        ])->assertOk()->assertJsonPath('data.buyer_name', 'Cliente de teste');
        $this->assertDatabaseHas('repasse_case_events', [
            'repasse_case_id' => $id, 'event_type' => 'vehicle_entry_voided',
        ]);
    }

    public function test_permissions_validation_archiving_and_case_ownership(): void
    {
        $this->getJson('/api/v1/repasse-cases/1/finance/summary')->assertUnauthorized();
        $unprivileged = $this->token(false);
        $this->withToken($unprivileged)
            ->getJson('/api/v1/repasse-cases/1/finance/entries')->assertForbidden();

        $token = $this->token();
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Veículo seguro',
        ])->assertCreated()->json('data.id');
        $other = $this->withToken($token)->postJson('/api/v1/repasse-cases', [
            'title' => 'Outro veículo',
        ])->assertCreated()->json('data.id');

        $base = "/api/v1/repasse-cases/$id";
        $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'frete',
            'description' => 'Valor incorreto', 'amount_cents' => -5,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'frete',
            'description' => 'Transferência indevida', 'amount_cents' => 100,
            'pix_key' => 'proibido',
        ])->assertUnprocessable()->assertJsonValidationErrors(['pix_key']);
        $entry = $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'frete',
            'description' => 'Transporte agendado', 'amount_cents' => 100,
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/repasse-cases/$other/finance/entries/$entry/void", [])
            ->assertNotFound();

        $this->withToken($token)->patchJson($base, ['status' => 'archived'])->assertOk();
        $this->withToken($token)->patchJson($base, ['plate' => 'AAA1B22'])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/finance/entries", [
            'kind' => 'expense', 'status' => 'planned', 'category' => 'frete',
            'description' => 'Bloqueado', 'amount_cents' => 100,
        ])->assertUnprocessable();
        $this->withToken($token)->postJson("$base/finance/entries/$entry/void", [])
            ->assertUnprocessable();
    }
}
