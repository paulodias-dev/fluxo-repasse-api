<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Facade tenant-safe para todas as queries operacionais legadas da API.
 * Tabelas técnicas sem dados de negócio (permissões, sessões, migrations)
 * permanecem globais, e todas as 32 tabelas operacionais exigem tenant.
 */
final class TenantDB
{
    public const TABLES = [
        'users', 'auth_security_events', 'parties', 'party_events', 'party_links',
        'repasse_cases', 'repasse_case_parties', 'repasse_case_events',
        'repasse_case_partnerships',
        'repasse_case_tasks', 'repasse_case_checklist', 'repasse_case_checklist_sets',
        'repasse_case_task_documents', 'repasse_case_notes',
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
        'repasse_settlements', 'repasse_settlement_lines',
        'repasse_settlement_payments', 'repasse_settlement_events',
        'vehicle_create_requests',
    ];

    public static function qualifierFor(string $table): ?string
    {
        // FROM customers as c, JOIN customers c, com aliases simples.
        $pieces = preg_split('/\s+(?:as\s+)?/i', trim($table));
        $name = $pieces[0] ?? '';

        if (! in_array($name, self::TABLES, true)) {
            return null;
        }

        return $pieces[1] ?? $name;
    }

    public static function table(string $table): Builder
    {
        $alias = self::qualifierFor($table);
        if ($alias === null) {
            return DB::table($table);
        }

        return (new TenantQueryBuilder(DB::connection()))
            ->scopeTo(TenantContext::requireId(), $table, $alias);
    }
}
