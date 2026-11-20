<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Garante um catálogo inicial de categorias financeiras por tenant.
 *
 * O seeder é idempotente: não altera categorias existentes, inclusive as
 * arquivadas, e pode ser executado novamente após novas instalações.
 */
final class FinanceCategoriesSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const CATEGORIES = [
        'expense' => [
            'Oficina',
            'Combustível',
            'Mecânica',
            'Peças',
            'Lavagem',
            'Guincho',
            'Documentação',
            'Impostos e multas',
            'Seguro',
            'Marketing',
            'Transporte',
        ],
        'income' => [
            'Receita extra',
            'Serviço extra',
            'Reembolso',
            'Venda de itens',
            'Outras receitas',
        ],
    ];

    public function run(): void
    {
        $now = now();

        $tenants = DB::table('tenants')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id']);

        foreach ($tenants as $tenant) {
            $createdBy = DB::table('users')
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->value('id');

            foreach (self::CATEGORIES as $kind => $categories) {
                foreach ($categories as $name) {
                    $slug = Str::slug($name);
                    $exists = DB::table('repasse_financial_categories')
                        ->where('tenant_id', $tenant->id)
                        ->where('kind', $kind)
                        ->where('slug', $slug)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('repasse_financial_categories')->insert([
                        'tenant_id' => $tenant->id,
                        'kind' => $kind,
                        'name' => $name,
                        'slug' => $slug,
                        'is_active' => true,
                        'created_by' => $createdBy,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        $this->command?->info('Categorias financeiras padrão garantidas para todos os tenants ativos.');
    }
}
