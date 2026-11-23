<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FinanceCategoryCatalog
{
    /** @var array<string, list<string>> */
    public const CATEGORIES = [
        'expense' => [
            'Geral',
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
            'Geral',
            'Receita extra',
            'Serviço extra',
            'Reembolso',
            'Venda de itens',
            'Outras receitas',
        ],
    ];

    public function ensureForTenant(int $tenantId, ?int $createdBy = null): void
    {
        $createdBy ??= DB::table('users')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');

        $now = now();
        foreach (self::CATEGORIES as $kind => $categories) {
            foreach ($categories as $name) {
                DB::table('repasse_financial_categories')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'kind' => $kind,
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'is_active' => true,
                    'created_by' => $createdBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
