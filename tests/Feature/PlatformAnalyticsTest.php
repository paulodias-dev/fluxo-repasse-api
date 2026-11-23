<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PlatformAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function login(bool $operator = false): array
    {
        $tenant = Tenant::create(['name' => $operator ? 'Central' : 'Cliente',
            'slug' => $operator ? 'central-bi' : 'cliente-bi', 'is_active' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        if ($operator) {
            $role = Role::query()->firstOrCreate(
                ['slug' => 'platform_admin'], ['name' => 'Admin plataforma']
            );
            $user->roles()->attach($role->id);
        }
        return [$tenant, $user, auth('api')->login($user)];
    }

    public function test_only_global_operator_reads_aggregates_and_company_360(): void
    {
        $this->getJson('/api/v1/platform/analytics/overview')->assertUnauthorized();
        [$tenant, $user, $ordinaryToken] = $this->login();
        $this->withToken($ordinaryToken)->getJson('/api/v1/platform/analytics/overview')
            ->assertForbidden();
        $this->withToken($ordinaryToken)->getJson('/api/v1/platform/companies/'.$tenant->id.'/360')
            ->assertForbidden();

        // Remove test actor's tenant to avoid a duplicate slug.
        [$adminTenant, $admin, $adminToken] = $this->login(true);
        $this->withToken($adminToken)->getJson('/api/v1/platform/analytics/overview')
            ->assertOk()->assertJsonPath('metrics.companies_total', 2)
            ->assertJsonMissingPath('users.0.email');
        $this->withToken($adminToken)->getJson('/api/v1/platform/companies/'.$tenant->id.'/360')
            ->assertOk()->assertJsonPath('company.name', 'Cliente');
        $this->withToken($adminToken)->getJson('/api/v1/platform/companies/999999/360')
            ->assertNotFound();
    }

    public function test_prod_revenue_and_mrr_exclude_sandbox_and_stale_subscriptions(): void
    {
        [$tenant, $admin, $jwt] = $this->login(true);
        $plan = DB::table('saas_plans')->insertGetId([
            'name' => 'BI', 'monthly_cents' => 4990,
            'available' => true, 'default_free' => false,
        ]);
        foreach ([
            ['id' => '11111111-1111-4111-8111-111111111111',
                'environment' => 'production', 'status' => 'authorized',
                'monthly_cents' => 4990, 'paid_until' => now()->addDays(10)],
            ['id' => '22222222-2222-4222-8222-222222222222',
                'environment' => 'test', 'status' => 'authorized',
                'monthly_cents' => 9990, 'paid_until' => now()->addDays(10)],
        ] as $sub) {
            DB::table('saas_subscriptions')->insert([
                'tenant_id' => $tenant->id, 'plan_id' => $plan,
                ...$sub, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach ([
            ['environment' => 'production', 'provider_id' => 'charge-prod', 'amount_cents' => 4990],
            ['environment' => 'test', 'provider_id' => 'charge-test', 'amount_cents' => 9990],
        ] as $charge) {
            DB::table('saas_charges')->insert([
                'tenant_id' => $tenant->id,
                'subscription_id' => $charge['environment'] === 'test'
                    ? '22222222-2222-4222-8222-222222222222'
                    : '11111111-1111-4111-8111-111111111111',
                'status' => 'approved', ...$charge,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->withToken($jwt)->getJson('/api/v1/platform/analytics/overview?days=30')
            ->assertOk()->assertJsonPath('period.environment', 'production')
            ->assertJsonPath('metrics.mrr_cents', 4990)
            ->assertJsonPath('metrics.revenue_cents', 4990)
            ->assertJsonPath('metrics.charges_approved', 1);
        $this->withToken($jwt)->getJson('/api/v1/platform/analytics/overview?environment=test')
            ->assertOk()->assertJsonPath('metrics.revenue_cents', 9990);
        $this->withToken($jwt)->getJson('/api/v1/platform/companies/'.$tenant->id.'/360')
            ->assertOk()->assertJsonPath('billing.received_cents', 4990);
    }

    public function test_invalid_periods_cannot_trigger_unbounded_analytics(): void
    {
        [, , $jwt] = $this->login(true);
        foreach (['?days=999', '?from=2020-01-01&to=2026-11-23',
            '?from=2026-11-23', '?environment=unknown',
            '?days=30&from=2026-11-01&to=2026-11-23'] as $bad) {
            $this->withToken($jwt)->getJson('/api/v1/platform/analytics/overview'.$bad)
                ->assertUnprocessable();
        }
    }
}
