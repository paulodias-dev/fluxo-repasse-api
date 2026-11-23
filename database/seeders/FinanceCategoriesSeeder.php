<?php

namespace Database\Seeders;

use App\Services\FinanceCategoryCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Garante um catálogo inicial de categorias financeiras por tenant.
 *
 * O seeder é idempotente: não altera categorias existentes, inclusive as
 * arquivadas, e pode ser executado novamente após novas instalações.
 */
final class FinanceCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = DB::table('tenants')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id']);
        $catalog = app(FinanceCategoryCatalog::class);

        foreach ($tenants as $tenant) {
            $createdBy = DB::table('users')
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->value('id');
            $catalog->ensureForTenant((int) $tenant->id, $createdBy === null ? null : (int) $createdBy);
        }

        $this->command?->info('Categorias financeiras padrão garantidas para todos os tenants ativos.');
    }
}
