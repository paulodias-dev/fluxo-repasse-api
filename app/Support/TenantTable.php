<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Escopo explícito de todas as tabelas de negócio existentes.
 * Proíbe leitura/escrita entre empresas e injeta tenant_id em novas linhas.
 * Sem tenant autenticado, falha fechado (403), não retorna dados globais.
 */
final class TenantTable
{
    public static function table(string $name): Builder
    {
        $base = trim(preg_split('/\s+as\s+/i', $name)[0]);
        $alias = trim(preg_split('/\s+as\s+/i', $name)[1] ?? $base);
        if (! self::owned($base)) {
            return DB::table($name);
        }

        $id = TenantContext::id();
        $connection = DB::connection();
        $query = new TenantQueryBuilder(
            $connection, $connection->getQueryGrammar(), $connection->getPostProcessor()
        );
        $query->from($name)->where($alias.'.tenant_id', $id);
        $query->forTenant($id, $alias);

        return $query;
    }

    public static function owned(string $table): bool
    {
        return in_array($table, [
            'users', 'roles', 'role_user', 'permission_role',
            'parties', 'party_events', 'party_links',
            'repasse_cases', 'repasse_case_parties', 'repasse_case_events',
            'repasse_case_tasks', 'repasse_case_checklist', 'repasse_case_notes',
            'repasse_case_evidence', 'repasse_scenarios',
            'repasse_scenario_allocations', 'repasse_scenario_versions',
            'repasse_distribution_templates', 'repasse_distribution_lines',
            'repasse_distribution_versions', 'repasse_distribution_batches',
            'repasse_scenario_reviews', 'repasse_scenario_review_events',
            'repasse_case_packages', 'repasse_case_financial_entries',
            'repasse_financial_categories', 'repasse_case_financial_documents',
            'repasse_case_financial_events', 'repasse_commercial_terms',
            'repasse_commercial_term_versions', 'repasse_commercial_term_evidence',
            'repasse_commercial_reviews', 'repasse_commercial_review_events',
            'repasse_preparation_records', 'repasse_obligation_proposals',
            'repasse_external_transfer_reports',
        ], true);
    }
}
