<?php

namespace Database\Seeders;

use App\Support\TenantRoles;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommercialTermsService;
use App\Services\DistributionTemplateService;
use App\Services\ScenarioPlanner;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dados FICTIONAIS para testar TODOS os tenants sem criar pagamentos falsos.
 * Somente local/testing. Instalação limpa: php artisan migrate:fresh --seed
 * REPASSE_DEMO_PASSWORD permite definir previamente a senha de teste.
 */
final class SaasDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demonstração desabilitada fora de local/testing.');
            return;
        }

        $configured = env('REPASSE_DEMO_PASSWORD');
        $password = is_string($configured) && strlen($configured) >= 12
            ? $configured : Str::password(20, symbols: true);
        $createdUsers = false;

        $companies = [
            [
                'slug' => 'demo-alfa', 'name' => 'Revenda Alfa — DEMONSTRAÇÃO',
                'label' => 'Alfa',
            ],
            [
                'slug' => 'demo-beta', 'name' => 'Repasse Beta — DEMONSTRAÇÃO',
                'label' => 'Beta',
            ],
        ];

        foreach ($companies as $company) {
            DB::transaction(function () use (
                $company, $password, &$createdUsers
            ): void {
                $tenant = Tenant::query()->firstOrCreate(
                    ['slug' => $company['slug']],
                    ['name' => $company['name'], 'is_active' => true]
                );
                $roles = TenantRoles::provision((int) $tenant->id);
                $adminRole = $roles['tenant_admin'];
                $userRole = $roles['tenant_user'];
                $slug = $company['slug'];
                $adminEmail = 'admin.'.$slug.'@example.invalid';
                $userEmail = 'usuario.'.$slug.'@example.invalid';

                $admin = User::query()->firstOrCreate(
                    ['email' => $adminEmail],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => 'Administrador '.$company['label'].' (DEMO)',
                        'password' => Hash::make($password),
                        'is_active' => true,
                    ]
                );
                if ($admin->wasRecentlyCreated) $createdUsers = true;
                $admin->roles()->syncWithoutDetaching([$adminRole->id]);

                $user = User::query()->firstOrCreate(
                    ['email' => $userEmail],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => 'Operador '.$company['label'].' (DEMO)',
                        'password' => Hash::make($password),
                        'is_active' => true,
                    ]
                );
                if ($user->wasRecentlyCreated) $createdUsers = true;
                $user->roles()->syncWithoutDetaching([$userRole->id]);

                $this->populateTenant((int) $tenant->id, $company['label'], $admin, $user);
            });
        }

        if ($createdUsers) {
            $this->command?->warn('=== CONTAS SOMENTE DE DEMONSTRAÇÃO ===');
            $this->command?->line('admin.demo-alfa@example.invalid  |  usuario.demo-alfa@example.invalid');
            $this->command?->line('admin.demo-beta@example.invalid  |  usuario.demo-beta@example.invalid');
            $this->command?->line('Senha: '.($configured && strlen($configured) >= 12
                ? '(configurada por REPASSE_DEMO_PASSWORD)' : $password));
            $this->command?->warn('Troque as senhas e NÃO use estes usuários em produção.');
        }
    }

    private function firstId(string $table, int $tenantId, array $unique, array $values): int
    {
        $query = DB::table($table)->where('tenant_id', $tenantId);
        foreach ($unique as $key => $value) $query->where($key, $value);
        $id = $query->value('id');
        if ($id !== null) return (int) $id;

        return (int) DB::table($table)->insertGetId([
            'tenant_id' => $tenantId,
            ...$unique, ...$values,
        ]);
    }

    private function populateTenant(int $tenantId, string $label, User $admin, User $user): void
    {
        $now = now();
        $partyIds = [];
        $parties = [
            ['person', 'Proprietário '.$label.' (DEMO)'],
            ['person', 'Sócio 1 '.$label.' (DEMO)'],
            ['person', 'Sócio 2 '.$label.' (DEMO)'],
            ['person', 'Intermediador '.$label.' (DEMO)'],
            ['company', 'Fornecedor '.$label.' (DEMO)'],
        ];
        foreach ($parties as [$type, $name]) {
            $partyIds[] = $this->firstId('parties', $tenantId,
                ['name' => $name], [
                    'type' => $type, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
        }

        $link = DB::table('party_links')
            ->where('tenant_id', $tenantId)->where('party_id', $partyIds[0])
            ->where('related_party_id', $partyIds[1])->first();
        if (! $link) {
            DB::table('party_links')->insert([
                'tenant_id' => $tenantId,
                'party_id' => $partyIds[0],
                'related_party_id' => $partyIds[1],
                'description' => 'Participação societária fictícia',
                'is_active' => true,
                'created_by' => $admin->id, 'updated_by' => $admin->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $cats = [];
        foreach ([
            ['expense', 'Oficina', 'oficina'],
            ['expense', 'Documentação', 'documentacao'],
            ['income', 'Receita extra', 'receita-extra'],
        ] as [$kind, $name, $slug]) {
            $cats[$slug] = $this->firstId(
                'repasse_financial_categories', $tenantId,
                ['kind' => $kind, 'slug' => $slug],
                ['name' => $name, 'is_active' => true,
                    'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        // Estoque, venda e veículo concluído, representando o fluxo do MVP.
        // Placas e empresas são FICTÍCIAS e não correspondem a veículos reais.
        $vehicles = [
            [
                'title' => 'Hatch em estoque — '.$label.' DEMO',
                'status' => 'draft', 'brand' => 'Modelo exemplo',
                'model' => 'Hatch fictício', 'plate' => null,
                'purchase_amount_cents' => 4200000, 'sale_amount_cents' => null,
                'purchased_on' => '2026-11-02', 'sold_on' => null,
            ],
            [
                'title' => 'Sedã vendido — '.$label.' DEMO',
                'status' => 'draft', 'brand' => 'Modelo exemplo',
                'model' => 'Sedã fictício', 'plate' => null,
                'purchase_amount_cents' => 5750000, 'sale_amount_cents' => 6700000,
                'purchased_on' => '2026-10-10', 'sold_on' => '2026-11-12',
            ],
            [
                'title' => 'SUV concluído — '.$label.' DEMO',
                'status' => 'archived', 'brand' => 'Modelo exemplo',
                'model' => 'SUV fictício', 'plate' => null,
                'purchase_amount_cents' => 7800000, 'sale_amount_cents' => 9100000,
                'purchased_on' => '2026-09-15', 'sold_on' => '2026-10-30',
            ],
        ];

        $firstDraft = null;
        foreach ($vehicles as $index => $vehicle) {
            $id = $this->firstId('repasse_cases', $tenantId,
                ['title' => $vehicle['title']], [
                    ...$vehicle, 'created_by' => $admin->id,
                    'updated_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            if ($index === 0) $firstDraft = $id;

            foreach (array_slice($partyIds, 0, 4) as $partyId) {
                $this->firstId('repasse_case_parties', $tenantId, [
                    'repasse_case_id' => $id, 'party_id' => $partyId,
                ], [
                    'description' => 'Participante de demonstração',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $this->firstId('repasse_case_tasks', $tenantId, [
                'repasse_case_id' => $id, 'title' => 'Conferir documentos (DEMO)',
            ], [
                'status' => $index === 2 ? 'done' : 'pending',
                'priority' => 'normal', 'assigned_to' => $user->id,
                'created_by' => $admin->id,
                'completed_at' => $index === 2 ? $now : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $this->firstId('repasse_case_checklist', $tenantId, [
                'repasse_case_id' => $id, 'label' => 'Conferir dados cadastrais (DEMO)',
            ], [
                'created_by' => $admin->id,
                'checked_by' => $index === 2 ? $admin->id : null,
                'checked_at' => $index === 2 ? $now : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // As entradas são exclusivamente de demonstração gerencial.
            $this->firstId('repasse_case_financial_entries', $tenantId, [
                'repasse_case_id' => $id,
                'description' => 'Serviço de oficina (DEMO)',
            ], [
                'kind' => 'expense', 'status' => 'realized',
                'category' => 'Oficina', 'category_id' => $cats['oficina'],
                'amount_cents' => 85000 + ($index * 20000),
                'effective_on' => '2026-11-04',
                'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->firstId('repasse_case_financial_entries', $tenantId, [
                'repasse_case_id' => $id,
                'description' => 'Despesa futura de documentação (DEMO)',
            ], [
                'kind' => 'expense', 'status' => 'planned',
                'category' => 'Documentação', 'category_id' => $cats['documentacao'],
                'amount_cents' => 35000,
                'created_by' => $user->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($index === 1) {
                $this->firstId('repasse_case_financial_entries', $tenantId, [
                    'repasse_case_id' => $id,
                    'description' => 'Receita acessória (DEMO)',
                ], [
                    'kind' => 'income', 'status' => 'realized',
                    'category' => 'Receita extra', 'category_id' => $cats['receita-extra'],
                    'amount_cents' => 12000, 'effective_on' => '2026-11-13',
                    'created_by' => $admin->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        if ($firstDraft === null) return;

        // Usa os MESMOS serviços da API para gerar hashes e versões válidas,
        // sem inserir minutas ou históricos artificiais inconsistentes.
        $seedRequest = Request::create('/demo/seed', 'POST');
        $seedRequest->setUserResolver(static fn (?string $guard = null) => $admin);
        request()->attributes->set('verified_tenant_id', $tenantId);
        try {
            $referenceHex = md5('fluxo-repasse:'.$tenantId.':'.$firstDraft);
            $reference = sprintf('%s-%s-4%s-a%s-%s',
                substr($referenceHex, 0, 8), substr($referenceHex, 8, 4),
                substr($referenceHex, 13, 3), substr($referenceHex, 17, 3),
                substr($referenceHex, 20, 12));
            if (! DB::table('repasse_scenarios')
                ->where('tenant_id', $tenantId)->where('client_reference', $reference)->exists()) {
                app(ScenarioPlanner::class)->create([
                    'case_id' => $firstDraft,
                    'client_reference' => $reference,
                    'title' => 'Simulação 60/40 — DEMO',
                    'description' => 'Exemplo hipotético, não gera dívida',
                    'gross_amount_cents' => 1000000,
                    'source_party_id' => $partyIds[0],
                    'allocations' => [
                        ['party_id' => $partyIds[1], 'amount_cents' => 600000, 'memo' => null],
                        ['party_id' => $partyIds[2], 'amount_cents' => 400000, 'memo' => null],
                    ],
                ], $seedRequest);
            }

            $template = DB::table('repasse_distribution_templates')
                ->where('tenant_id', $tenantId)
                ->where('repasse_case_id', $firstDraft)
                ->where('name', 'Modelo demonstrativo 60/40')->first();
            if (! $template) {
                $saved = app(DistributionTemplateService::class)->create(
                    $firstDraft,
                    [
                        'name' => 'Modelo demonstrativo 60/40',
                        'description' => 'Somente uma sugestão demonstrativa, sem obrigação financeira',
                        'source_party_id' => $partyIds[0],
                        'lines' => [
                            ['party_id' => $partyIds[1], 'basis_points' => 6000, 'memo' => null],
                            ['party_id' => $partyIds[2], 'basis_points' => 4000, 'memo' => null],
                        ],
                    ],
                    $seedRequest
                );
                $templateId = (int) $saved['id'];
            } else {
                $templateId = (int) $template->id;
            }

            $existingTerm = DB::table('repasse_commercial_terms')
                ->where('tenant_id', $tenantId)
                ->where('repasse_case_id', $firstDraft)
                ->where('title', 'Condições ilustrativas — DEMO')->exists();
            if (! $existingTerm) {
                app(CommercialTermsService::class)->create(
                    $firstDraft,
                    [
                        'title' => 'Condições ilustrativas — DEMO',
                        'description' => 'Exemplo de formulário comercial, não aprovado para pagamento',
                        'template_id' => $templateId, 'template_revision' => 1,
                        'reference_cents' => 1000000,
                        'conditions' => [
                            'event_trigger' => 'Venda do veículo (exemplo)',
                            'calculation_basis' => 'R$ 10.000 informados, apenas simulação',
                            'authorization' => 'Exige confirmação de usuário autorizado',
                            'banking' => 'Pagamento realizado fora do SaaS',
                        ],
                    ],
                    $seedRequest
                );
            }
        } finally {
            request()->attributes->remove('verified_tenant_id');
        }
    }
}
