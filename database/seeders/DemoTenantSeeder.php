<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DistributionTemplateService;
use App\Services\ScenarioPlanner;
use App\Support\OperationalAccessCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Somente para desenvolvimento/testes. Dados 100% fictícios.
 * Credenciais aleatórias impressas UMA VEZ no console se não fornecidas
 * por REPASSE_DEMO_PASSWORD. Nunca execute este seeder em produção.
 */
final class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Seeder de demonstração proibido fora de local/testing.');
        }

        $password = (string) (env('REPASSE_DEMO_PASSWORD') ?: Str::password(24));
        $configs = [
            ['slug' => 'demonstracao-aracaju', 'name' => 'Empresa Demonstração Aracaju',
                'prefix' => 'aracaju'],
            ['slug' => 'demonstracao-brasilia', 'name' => 'Empresa Demonstração Brasília',
                'prefix' => 'brasilia'],
        ];

        // Primeiro cria todos os tenants, papéis e contas SEM sessão impersonada.
        $companies = [];
        foreach ($configs as $config) {
            $tenant = DB::table('tenants')->where('slug', $config['slug'])->first();
            if (! $tenant) {
                $id = DB::table('tenants')->insertGetId([
                    'slug' => $config['slug'], 'name' => $config['name'],
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $tenant = DB::table('tenants')->where('id', $id)->first();
            }

            $roles = [];
            foreach ([
                'administrator' => [
                    'name' => 'Administrador',
                    'permissions' => OperationalAccessCatalog::keys(),
                ],
                'user' => [
                    'name' => 'Usuário',
                    'permissions' => [
                        'cases.manage', 'parties.manage', 'scenarios.manage',
                        'packages.manage',
                    ],
                ],
            ] as $slug => $definition) {
                $role = Role::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $slug],
                    ['name' => $definition['name']]
                );
                $permissionIds = Permission::query()
                    ->whereIn('key', $definition['permissions'])->pluck('id')->all();
                $role->permissions()->sync($permissionIds);
                $roles[$slug] = $role;
            }

            $accounts = [];
            foreach ([
                ['slug' => 'administrator', 'label' => 'Administrador'],
                ['slug' => 'user', 'label' => 'Usuário'],
            ] as $account) {
                $email = $account['slug'].'@'.$config['prefix'].'.fluxorepasse.test';
                $user = User::query()->updateOrCreate(
                    ['email' => $email],
                    [
                        'name' => $account['label'].' Demo '.$config['prefix'],
                        'tenant_id' => $tenant->id,
                        'password' => $password,
                    ]
                );
                $user->roles()->sync([$roles[$account['slug']]->id]);
                $accounts[$account['slug']] = $user;
                $this->command?->line($config['name'].' · '.$account['label'].': '.$email);
            }

            $companies[] = [
                'tenant' => $tenant, 'users' => $accounts, 'prefix' => $config['prefix'],
            ];
        }

        // Serviços de domínio geram hashes e versões históricos REAIS.
        // A sessão simulada existe somente durante o seeder local, sem JWT emitido.
        try {
            foreach ($companies as $company) {
                Auth::guard('api')->setUser($company['users']['administrator']);
                $this->seedCompany($company);
            }
        } finally {
            Auth::guard('api')->forgetUser();
        }

        $this->command?->warn('SOMENTE DEMONSTRAÇÃO — dados e empresas fictícios; não usar em produção.');
        if (! env('REPASSE_DEMO_PASSWORD')) {
            $this->command?->warn('Senha temporária das 4 contas: '.$password);
            $this->command?->warn('Guarde a senha agora; ela não é armazenada em texto puro.');
        } else {
            $this->command?->line('Senha definida externamente por REPASSE_DEMO_PASSWORD.');
        }
    }

    /** @param array{tenant: object, users: array<string, User>, prefix: string} $company */
    private function seedCompany(array $company): void
    {
        $tenantId = (int) $company['tenant']->id;
        $admin = $company['users']['administrator'];
        $prefix = $company['prefix'];

        DB::transaction(function () use ($tenantId, $admin, $prefix): void {
            $parties = [];
            foreach ([
                'seller' => ['person', 'Vendedor Demo '.ucfirst($prefix)],
                'buyer' => ['person', 'Comprador Demo '.ucfirst($prefix)],
                'partner' => ['person', 'Sócio Demo '.ucfirst($prefix)],
                'workshop' => ['company', 'Oficina Demonstração '.ucfirst($prefix)],
            ] as $key => [$type, $name]) {
                $parties[$key] = DB::table('parties')->insertGetId([
                    'tenant_id' => $tenantId, 'type' => $type, 'name' => $name,
                    'email' => null, 'phone' => null,
                    'notes' => 'Dados fictícios para testes do Fluxo Repasse.',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('party_events')->insert([
                    'tenant_id' => $tenantId, 'party_id' => $parties[$key],
                    'actor_user_id' => $admin->id,
                    'event_type' => 'party_created', 'created_at' => now(),
                ]);
            }

            $categoryIds = [];
            foreach ([
                ['expense', 'Documentação', 'documentacao'],
                ['expense', 'Oficina', 'oficina'],
                ['expense', 'Transporte', 'transporte'],
                ['income', 'Receita extra', 'receita-extra'],
            ] as [$kind, $name, $slug]) {
                $categoryIds[$slug] = DB::table('repasse_financial_categories')->insertGetId([
                    'tenant_id' => $tenantId, 'kind' => $kind, 'name' => $name,
                    'slug' => $slug, 'is_active' => true, 'created_by' => $admin->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach ([
                ['Hatch Demonstração', 'ABC1D23', 'Hyundai', 'HB20', 2022,
                    4250000, null, 'draft', null],
                ['Sedã Vendido Demo', 'DEF2G34', 'Toyota', 'Corolla', 2020,
                    6150000, 7320000, 'draft', now()->subDays(3)->toDateString()],
                ['SUV Arquivado Demo', 'GHI3J45', 'Jeep', 'Renegade', 2021,
                    6900000, 7950000, 'archived', now()->subDays(12)->toDateString()],
            ] as $index => $item) {
                [$title, $plate, $brand, $model, $year, $purchase, $sale, $status, $soldOn] = $item;
                $caseId = DB::table('repasse_cases')->insertGetId([
                    'tenant_id' => $tenantId,
                    'title' => $title.' · '.ucfirst($prefix),
                    'description' => 'Veículo fictício para testes integrados do SaaS.',
                    'status' => $status,
                    'plate' => $plate, 'brand' => $brand, 'model' => $model,
                    'model_year' => $year,
                    'purchased_on' => now()->subDays(45 + $index * 10)->toDateString(),
                    'sold_on' => $soldOn,
                    'purchase_amount_cents' => $purchase,
                    'sale_amount_cents' => $sale,
                    'seller_name' => 'Vendedor Demo '.ucfirst($prefix),
                    'buyer_name' => $sale ? 'Comprador Demo '.ucfirst($prefix) : null,
                    'created_by' => $admin->id, 'updated_by' => $admin->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach (['seller', 'buyer', 'partner', 'workshop'] as $relation) {
                    DB::table('repasse_case_parties')->insert([
                        'tenant_id' => $tenantId, 'repasse_case_id' => $caseId,
                        'party_id' => $parties[$relation],
                        'description' => $relation, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                DB::table('repasse_case_events')->insert([
                    'tenant_id' => $tenantId, 'repasse_case_id' => $caseId,
                    'actor_user_id' => $admin->id, 'event_type' => 'case_created',
                    'created_at' => now(),
                ]);
                if ($status === 'draft') {
                    DB::table('repasse_case_tasks')->insert([
                        'tenant_id' => $tenantId, 'repasse_case_id' => $caseId,
                        'title' => $index === 0 ? 'Conferir documentação de compra'
                            : 'Conferir documentação da venda',
                        'status' => 'pending', 'priority' => 'normal',
                        'created_by' => $admin->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('repasse_case_checklist')->insert([
                        'tenant_id' => $tenantId, 'repasse_case_id' => $caseId,
                        'label' => 'Validar documentos do veículo',
                        'created_by' => $admin->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $expenses = [
                    ['expense', 'realized', 'Oficina', 'Revisão mecânica de demonstração',
                        85000, 'oficina'],
                    ['expense', 'planned', 'Documentação', 'Transferência prevista', 52000,
                        'documentacao'],
                    ['income', 'realized', 'Receita extra', 'Serviço extra demonstrativo',
                        22000, 'receita-extra'],
                ];
                foreach ($expenses as [$kind, $state, $category, $description, $cents, $slug]) {
                    DB::table('repasse_case_financial_entries')->insert([
                        'tenant_id' => $tenantId, 'repasse_case_id' => $caseId,
                        'kind' => $kind, 'status' => $state, 'category' => $category,
                        'category_id' => $categoryIds[$slug],
                        'description' => $description, 'amount_cents' => $cents,
                        'effective_on' => $state === 'realized' ? now()->subDays(8)->toDateString() : null,
                        'created_by' => $admin->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                // Cenários e modelos de rateio usam os mesmos serviços de domínio
                // da API: snapshots e hashes não são inventados no seeder.
                if ($index === 0) {
                    $request = Request::create('/api/v1/demo-seeder', 'POST');
                    $request->setUserResolver(static fn () => $admin);
                    app(ScenarioPlanner::class)->create([
                        'case_id' => $caseId,
                        'client_reference' => (string) Str::uuid(),
                        'title' => 'Simulação de rateio demonstrativa',
                        'description' => 'Exemplo, não cria obrigação nem pagamento.',
                        'gross_amount_cents' => 1000000,
                        'source_party_id' => $parties['seller'],
                        'allocations' => [
                            ['party_id' => $parties['seller'], 'amount_cents' => 600000],
                            ['party_id' => $parties['partner'], 'amount_cents' => 400000],
                        ],
                    ], $request);
                    app(DistributionTemplateService::class)->create($caseId, [
                        'name' => 'Modelo demonstrativo 60% / 40%',
                        'description' => 'Modelo de simulação sem obrigação financeira.',
                        'source_party_id' => $parties['seller'],
                        'lines' => [
                            ['party_id' => $parties['seller'], 'basis_points' => 6000],
                            ['party_id' => $parties['partner'], 'basis_points' => 4000],
                        ],
                    ], $request);
                }
            }
        });
    }
}
