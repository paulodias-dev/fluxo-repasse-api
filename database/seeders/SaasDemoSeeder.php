<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\Role;
use App\Models\User;
use App\Services\CommercialTermsService;
use App\Services\DistributionTemplateService;
use App\Services\ScenarioPlanner;
use App\Support\TenantContext;
use App\Support\TenantDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SaasDemoSeeder extends Seeder
{
    /**
     * Two INDEPENDENT fictitious companies, with working accounts and real
     * module flows. No real-world entity, payment, proof or tax is invented.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Seeder demonstrativo restrito aos ambientes local e testing.');
        }

        $password = getenv('REPASSE_DEMO_PASSWORD');
        $generated = ! is_string($password) || $password === '';
        $password = $generated ? Str::password(24) : $password;

        if (strlen($password) < 12) {
            throw new RuntimeException('REPASSE_DEMO_PASSWORD deve ter ao menos 12 caracteres.');
        }

        $adminRole = Role::query()->where('slug', 'tenant_admin')->firstOrFail();
        $userRole = Role::query()->where('slug', 'tenant_user')->firstOrFail();
        $companies = [
            ['slug' => 'demo-norte', 'name' => 'Empresa Demonstração Norte', 'label' => 'Norte'],
            ['slug' => 'demo-sul', 'name' => 'Empresa Demonstração Sul', 'label' => 'Sul'],
        ];

        foreach ($companies as $company) {
            $tenantId = $this->tenant($company);
            app(TenantContext::class)->run($tenantId, function () use (
                $company, $password, $adminRole, $userRole,
            ): void {
                [$admin] = $this->users($company, $password, $adminRole, $userRole);
                $this->examples($company, $admin);
            });
        }

        $this->command?->info('Dados demonstrativos: admin@demo-norte.test, usuario@demo-norte.test, admin@demo-sul.test e usuario@demo-sul.test.');
        if ($generated) {
            $this->command?->warn('Senha TEMPORÁRIA gerada somente para desenvolvimento: '.$password);
            $this->command?->warn('Guarde-a agora; ela não é persistida em texto e muda ao executar novamente o seeder sem REPASSE_DEMO_PASSWORD.');
        } else {
            $this->command?->info('As contas usam REPASSE_DEMO_PASSWORD informado no ambiente (sem registrar senha no log).');
        }
    }

    private function tenant(array $company): int
    {
        DB::table('tenants')->updateOrInsert(
            ['slug' => $company['slug']],
            ['name' => $company['name'], 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]
        );

        return (int) DB::table('tenants')->where('slug', $company['slug'])->value('id');
    }

    private function users(array $company, string $password, Role $adminRole, Role $userRole): array
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@'.$company['slug'].'.test'],
            ['name' => 'Administrador '.$company['label'], 'password' => $password,
                'tenant_id' => app(TenantContext::class)->id(), 'is_active' => true]
        );
        $operator = User::query()->updateOrCreate(
            ['email' => 'usuario@'.$company['slug'].'.test'],
            ['name' => 'Usuário '.$company['label'], 'password' => $password,
                'tenant_id' => app(TenantContext::class)->id(), 'is_active' => true]
        );
        $admin->roles()->sync([$adminRole->id]);
        $operator->roles()->sync([$userRole->id]);

        return [$admin, $operator];
    }

    private function examples(array $company, User $admin): void
    {
        $suffix = $company['label'];
        $origin = Party::query()->firstOrCreate(
            ['type' => 'company', 'name' => 'Investimentos '.$suffix.' (exemplo)'],
            ['email' => strtolower($suffix).'@investimentos.example.test']
        );
        $investor = Party::query()->firstOrCreate(
            ['type' => 'person', 'name' => 'Sócio '.$suffix.' (exemplo)']
        );
        $partner = Party::query()->firstOrCreate(
            ['type' => 'person', 'name' => 'Parceiro '.$suffix.' (exemplo)']
        );
        $supplier = Party::query()->firstOrCreate(
            ['type' => 'company', 'name' => 'Oficina '.$suffix.' (exemplo)']
        );

        if (! TenantDatabase::table('party_links')->where('party_id', $origin->id)
            ->where('related_party_id', $investor->id)->exists()) {
            TenantDatabase::table('party_links')->insert([
                'party_id' => $origin->id, 'related_party_id' => $investor->id,
                'description' => 'Sócio da empresa (vínculo fictício)',
                'is_active' => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $categories = [
            ['expense', 'Documentação', 'documentacao'],
            ['expense', 'Manutenção', 'manutencao'],
            ['income', 'Reembolso', 'reembolso'],
        ];
        foreach ($categories as [$kind, $name, $slug]) {
            TenantDatabase::table('repasse_financial_categories')->updateOrInsert(
                ['kind' => $kind, 'slug' => $slug],
                ['name' => $name, 'is_active' => true, 'created_by' => $admin->id,
                    'updated_at' => now(), 'created_at' => now()]
            );
        }

        $fixtures = [
            ['title' => 'Sedan '.$suffix.' — venda demonstrativa', 'plate' => null,
                'brand' => 'Marca demonstrativa', 'model' => 'Sedan',
                'status' => 'draft', 'purchase' => 4200000, 'sale' => 5150000,
                'purchased' => now()->subDays(45)->toDateString(),
                'sold' => now()->subDays(3)->toDateString()],
            ['title' => 'SUV '.$suffix.' — em preparação', 'plate' => null,
                'brand' => 'Marca demonstrativa', 'model' => 'SUV',
                'status' => 'draft', 'purchase' => 7100000, 'sale' => null,
                'purchased' => now()->subDays(18)->toDateString(), 'sold' => null],
            ['title' => 'Hatch '.$suffix.' — concluído', 'plate' => null,
                'brand' => 'Marca demonstrativa', 'model' => 'Hatch',
                'status' => 'archived', 'purchase' => 3100000, 'sale' => 3650000,
                'purchased' => now()->subDays(78)->toDateString(),
                'sold' => now()->subDays(25)->toDateString()],
        ];

        $firstCaseId = null;
        foreach ($fixtures as $fixture) {
            $id = (int) (TenantDatabase::table('repasse_cases')
                ->where('title', $fixture['title'])->value('id') ?: 0);
            if ($id === 0) {
                $id = TenantDatabase::table('repasse_cases')->insertGetId([
                    'title' => $fixture['title'], 'description' => 'Registro exclusivo de demonstração, sem valores ou empresas reais.',
                    'status' => $fixture['status'], 'plate' => $fixture['plate'],
                    'brand' => $fixture['brand'], 'model' => $fixture['model'],
                    'purchase_amount_cents' => $fixture['purchase'],
                    'sale_amount_cents' => $fixture['sale'],
                    'purchased_on' => $fixture['purchased'], 'sold_on' => $fixture['sold'],
                    'created_by' => $admin->id, 'updated_by' => $admin->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $firstCaseId ??= $id;
            foreach ([$origin, $investor, $partner, $supplier] as $party) {
                if (! TenantDatabase::table('repasse_case_parties')->where('repasse_case_id', $id)
                    ->where('party_id', $party->id)->exists()) {
                    TenantDatabase::table('repasse_case_parties')->insert([
                        'repasse_case_id' => $id, 'party_id' => $party->id,
                        'description' => 'Participante demonstrativo',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        if ($firstCaseId === null) {
            throw new RuntimeException('Demonstração: não foi possível criar o veículo de exemplo.');
        }

        $this->task($firstCaseId, $admin->id);
        $this->financialEntries($firstCaseId, $admin->id);

        $request = Request::create('/demo/seed', 'POST');
        $request->setUserResolver(static fn ($guard = null): User => $admin);

        // Use the application's actual snapshot/digest services, never fake
        // hashes or documentary records that cannot pass integrity checks.
        if (! TenantDatabase::table('repasse_scenarios')->where('repasse_case_id', $firstCaseId)->exists()) {
            app(ScenarioPlanner::class)->create([
                'case_id' => $firstCaseId,
                'client_reference' => (string) Str::uuid(),
                'title' => 'Cenário de rateio '.$suffix,
                'description' => 'Simulação demonstrativa. Não é obrigação a pagar.',
                'gross_amount_cents' => 1000000,
                'source_party_id' => $origin->id,
                'allocations' => [
                    ['party_id' => $investor->id, 'amount_cents' => 600000],
                    ['party_id' => $partner->id, 'amount_cents' => 400000],
                ],
            ], $request);
        }

        if (! TenantDatabase::table('repasse_distribution_templates')->where('repasse_case_id', $firstCaseId)->exists()) {
            $created = app(DistributionTemplateService::class)->create($firstCaseId, [
                'name' => 'Divisão 60/40 — '.$suffix,
                'description' => 'Modelo de percentuais apenas para simulação.',
                'source_party_id' => $origin->id,
                'lines' => [
                    ['party_id' => $investor->id, 'basis_points' => 6000],
                    ['party_id' => $partner->id, 'basis_points' => 4000],
                ],
            ], $request);
            $template = $created;

            // Commercial sample is a legitimate versioned draft, not an
            // authorization, payment, tax calculation or financial debt.
            if (! TenantDatabase::table('repasse_commercial_terms')->where('repasse_case_id', $firstCaseId)->exists()) {
                app(CommercialTermsService::class)->create($firstCaseId, [
                    'title' => 'Minuta demonstrativa — '.$suffix,
                    'description' => 'Exemplo interno; requer conferência do operador.',
                    'template_id' => $template['id'],
                    'template_revision' => $template['revision'],
                    'reference_cents' => 1000000,
                    'conditions' => [
                        'event_trigger' => 'Somente após venda registrada',
                        'calculation_basis' => 'Valor definido pelo operador, apenas simulado',
                        'fees_commissions' => 'Percentual ou valor manual; nenhum padrão presumido',
                        'taxes_withholdings' => 'Lançamentos manuais quando aplicáveis',
                        'eligibility' => 'Participantes vinculados ao veículo',
                        'authorization' => 'Confirmação por usuário autorizado, quando implementada',
                        'settlement' => 'Pagamentos externos, sem integração bancária',
                        'cancellation' => 'Estorno com justificativa',
                        'banking' => 'Não há movimentação bancária pela plataforma',
                        'organization' => 'Empresa isolada pelo tenant_id',
                    ],
                ], $request);
            }
        }
    }

    private function task(int $caseId, int $adminId): void
    {
        if (! TenantDatabase::table('repasse_case_tasks')->where('repasse_case_id', $caseId)->exists()) {
            TenantDatabase::table('repasse_case_tasks')->insert([
                'repasse_case_id' => $caseId,
                'title' => 'Conferir documentação do veículo',
                'details' => 'Tarefa demonstrativa da operação',
                'priority' => 'normal', 'status' => 'pending',
                'due_on' => now()->addDays(4)->toDateString(),
                'assigned_to' => $adminId, 'created_by' => $adminId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! TenantDatabase::table('repasse_case_checklist')->where('repasse_case_id', $caseId)->exists()) {
            TenantDatabase::table('repasse_case_checklist')->insert([
                'repasse_case_id' => $caseId,
                'label' => 'Validar documentos da compra (exemplo)',
                'created_by' => $adminId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! TenantDatabase::table('repasse_case_notes')->where('repasse_case_id', $caseId)->exists()) {
            TenantDatabase::table('repasse_case_notes')->insert([
                'repasse_case_id' => $caseId,
                'body' => 'Nota demonstrativa da equipe. Não representa operação real.',
                'author_id' => $adminId, 'created_at' => now(),
            ]);
        }
    }

    private function financialEntries(int $caseId, int $adminId): void
    {
        if (TenantDatabase::table('repasse_case_financial_entries')
            ->where('repasse_case_id', $caseId)->exists()) {
            return;
        }

        foreach ([
            ['expense', 'realized', 'Documentação', 'Taxa documental (exemplo)', 45000, now()->subDays(5)->toDateString()],
            ['expense', 'planned', 'Manutenção', 'Revisão prevista (exemplo)', 80000, null],
            ['income', 'realized', 'Reembolso', 'Receita extra (exemplo)', 10000, now()->subDays(3)->toDateString()],
        ] as [$kind, $status, $category, $description, $amount, $day]) {
            TenantDatabase::table('repasse_case_financial_entries')->insert([
                'repasse_case_id' => $caseId,
                'kind' => $kind, 'status' => $status,
                'category' => $category, 'description' => $description,
                'amount_cents' => $amount, 'effective_on' => $day,
                'created_by' => $adminId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
