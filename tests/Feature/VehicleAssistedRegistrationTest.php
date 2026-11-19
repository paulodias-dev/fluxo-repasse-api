<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantRoles;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VehicleAssistedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdminUserSeeder::class);
        Cache::flush();
    }

    private function actor(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'is_active' => true]);
        $user = User::create(['tenant_id' => $tenant->id, 'name' => 'Admin '.$slug,
            'email' => $slug.'@example.invalid', 'password' => 'Senha&Aleatoria12345',
            'email_verified_at' => now()]);
        $roles = TenantRoles::provision((int) $tenant->id);
        $user->roles()->sync([$roles['tenant_admin']->id]);
        return [$tenant, auth('api')->login($user)];
    }

    public function test_consulta_exige_jwt_permissao_e_configuracao_sem_consultar_api_sem_token(): void
    {
        $this->getJson('/api/v1/vehicle-lookup/plate?plate=ABC1D23')->assertUnauthorized();
        [$tenant, $token] = $this->actor('consulta-56');
        config()->set('services.vehicle_plate.token', '');
        Http::fake();
        $this->withToken($token)->getJson('/api/v1/vehicle-lookup/plate?plate=ABC1D23')
            ->assertOk()->assertJsonPath('data.status', 'unavailable');
        $this->withToken($token)->getJson('/api/v1/vehicle-lookup/plate?plate=ABC!!')
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_placa_normalizada_e_dados_pessoais_descartados(): void
    {
        [$tenant, $token] = $this->actor('placa-56');
        config()->set('services.vehicle_plate.token', 'token-de-teste');
        Http::fake(function (Request $request) {
            return Http::response([
                'placa' => 'ABC1D23', 'marca' => 'VW', 'modelo' => 'POLO',
                'versao' => 'POLO HIGHLINE 1.0', 'ano_modelo' => 2023,
                'proprietario' => 'NUNCA_RETORNAR', 'cpf' => 'NUNCA_RETORNAR',
                'fipe' => ['codigo' => '005123-4', 'valor' => 98000,
                    'referencia' => 'novembro de 2026', 'versoes' => []],
            ]);
        });
        $r = $this->withToken($token)->getJson('/api/v1/vehicle-lookup/plate?plate=abc-1d23')
            ->assertOk()->assertJsonPath('data.vehicle.model', 'POLO')
            ->assertJsonPath('data.quote.fipe_price_cents', 9800000);
        $r->assertDontSee('NUNCA_RETORNAR');
        $this->assertEquals('ABC1D23', $r->json('data.plate'));
        Http::assertSentCount(1);
    }

    public function test_catalogo_e_cotacao_exata_com_idempotencia_sem_contaminar_compra(): void
    {
        [$tenant, $token] = $this->actor('fipe-56');
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            return Http::response(match ($path) {
                '/api/v2/cars/brands' => [['code' => '59', 'name' => 'VW - VolksWagen']],
                '/api/v2/cars/brands/59/models' => [
                    ['code' => '100', 'name' => 'POLO 1.0 MPI'],
                    ['code' => '101', 'name' => 'POLO HIGHLINE 1.0']],
                '/api/v2/cars/brands/59/years' => [['code' => '2023-5', 'name' => '2023 Flex']],
                '/api/v2/cars/brands/59/years/2023-5/models' => [
                    ['code' => '101', 'name' => 'POLO HIGHLINE 1.0']],
                '/api/v2/cars/brands/59/models/101/years/2023-5' => [
                    'brand' => 'VW - VolksWagen', 'model' => 'POLO HIGHLINE 1.0',
                    'modelYear' => 2023, 'price' => 'R$ 98.321,47',
                    'codeFipe' => '005123-4', 'referenceMonth' => 'novembro de 2026'],
                default => ['error' => 'not found'],
            }, $path === '/api/v2/cars/brands' || str_ends_with($path, '/models')
                || str_ends_with($path, '/years') ? 200 : 200);
        });
        $this->withToken($token)->getJson('/api/v1/vehicle-lookup/catalog/brands?type=cars')
            ->assertOk()->assertJsonPath('data.items.0.label', 'VW - VolksWagen');
        $this->withToken($token)->getJson('/api/v1/vehicle-lookup/catalog/models?type=cars&brand=59')
            ->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($token)->getJson('/api/v1/vehicle-lookup/catalog/versions?type=cars&brand=59&year=2023-5&family=POLO')
            ->assertOk()->assertJsonCount(1, 'data.items');
        $q = $this->withToken($token)->getJson(
            '/api/v1/vehicle-lookup/quote?type=cars&brand=59&model=101&year=2023-5'
        )->assertOk()->assertJsonPath('data.quote.fipe_price_cents', 9832147)->json('data.quote');
        $payload = ['request_key' => (string) Str::uuid(), 'title' => 'Polo de teste',
            'plate' => 'ABC1D23', 'brand' => $q['brand'], 'model' => $q['model'],
            'vehicle_version' => $q['vehicle_version'], 'model_year' => $q['model_year'],
            'vehicle_type' => $q['vehicle_type'], 'fipe_quote_token' => $q['token']];
        $created = $this->withToken($token)->postJson('/api/v1/repasse-cases', $payload)
            ->assertCreated()->assertJsonPath('data.fipe_price_cents', 9832147)
            ->assertJsonPath('data.purchase_amount_cents', null)->json('data.id');
        $this->withToken($token)->postJson('/api/v1/repasse-cases', $payload)
            ->assertOk()->assertJsonPath('created', false)->assertJsonPath('data.id', $created);
        $this->withToken($token)->postJson('/api/v1/repasse-cases',
            array_merge($payload, ['title' => 'Outro título']))->assertStatus(409);
        $this->assertSame(1, DB::table('repasse_cases')->where('tenant_id', $tenant->id)->count());
    }

    public function test_placa_duplicada_somente_no_tenant_e_origem_fipe_nao_pode_ser_forjada(): void
    {
        [$a, $tokenA] = $this->actor('empresa-a56');
        [$b, $tokenB] = $this->actor('empresa-b56');
        $payload = ['title' => 'Um veículo', 'plate' => 'ABC-1234'];
        $this->withToken($tokenA)->postJson('/api/v1/repasse-cases', $payload)
            ->assertCreated()->assertJsonPath('data.plate', 'ABC1234');
        $this->withToken($tokenA)->postJson('/api/v1/repasse-cases', $payload)
            ->assertUnprocessable();
        $this->withToken($tokenB)->postJson('/api/v1/repasse-cases', $payload)
            ->assertCreated()->assertJsonPath('data.plate', 'ABC1234');
        $this->withToken($tokenB)->postJson('/api/v1/repasse-cases',
            ['title' => 'Forjado', 'fipe_price_cents' => 99999999])->assertUnprocessable();
        $this->withToken($tokenA)->postJson('/api/v1/repasse-cases',
            ['title' => 'Forjado', 'tenant_id' => $b->id])->assertUnprocessable();
        $this->assertSame(1, DB::table('repasse_cases')->where('tenant_id', $a->id)->count());
        $this->assertSame(1, DB::table('repasse_cases')->where('tenant_id', $b->id)->count());
    }

    public function test_fipe_antiga_nao_permanece_apos_editar_identificacao(): void
    {
        [$t, $token] = $this->actor('invalida-fipe56');
        $id = $this->withToken($token)->postJson('/api/v1/repasse-cases',
            ['title' => 'Veículo', 'brand' => 'VW', 'model' => 'Polo',
             'plate' => 'AAA1234'])->assertCreated()->json('data.id');
        DB::table('repasse_cases')->where('id', $id)->update([
            'fipe_price_cents' => 5000000, 'fipe_code' => '005123-4',
            'fipe_reference_month' => 'outubro de 2026',
        ]);
        $this->withToken($token)->patchJson('/api/v1/repasse-cases/'.$id,
            ['model' => 'Golf'])->assertOk()->assertJsonPath('data.fipe_price_cents', null);
    }
}
