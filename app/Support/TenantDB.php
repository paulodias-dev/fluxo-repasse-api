<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Escopo explícito de TODOS os builders legados de negócio. A proteção
 * decorre da empresa verificada no JWT/membership, NUNCA de ID enviado pelo
 * cliente sem validação. Chamadas sem middleware tenant.active falham fechadas.
 */
final class TenantDB
{
    private const ROOTS = [
        'repasse_cases', 'parties', 'repasse_financial_categories',
    ];

    /** @var array<string,array{string,string}> */
    private const PARENTS = [
        'party_events' => ['parties', 'party_id'],
        'party_links' => ['parties', 'party_id'],
        'repasse_case_parties' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_events' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_tasks' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_checklist' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_notes' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_evidence' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_financial_entries' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_financial_documents' => ['repasse_cases', 'repasse_case_id'],
        'repasse_case_financial_events' => ['repasse_cases', 'repasse_case_id'],
        'repasse_scenarios' => ['repasse_cases', 'repasse_case_id'],
        'repasse_scenario_allocations' => ['repasse_scenarios', 'repasse_scenario_id'],
        'repasse_scenario_versions' => ['repasse_scenarios', 'repasse_scenario_id'],
        'repasse_scenario_reviews' => ['repasse_scenarios', 'repasse_scenario_id'],
        'repasse_scenario_review_events' => ['repasse_scenario_reviews', 'review_id'],
        'repasse_distribution_templates' => ['repasse_cases', 'repasse_case_id'],
        'repasse_distribution_lines' => ['repasse_distribution_templates', 'template_id'],
        'repasse_distribution_versions' => ['repasse_distribution_templates', 'template_id'],
        'repasse_distribution_batches' => ['repasse_distribution_templates', 'template_id'],
        'repasse_case_packages' => ['repasse_cases', 'repasse_case_id'],
        'repasse_commercial_terms' => ['repasse_cases', 'repasse_case_id'],
        'repasse_commercial_term_versions' => ['repasse_commercial_terms', 'term_id'],
        'repasse_commercial_term_evidence' => ['repasse_commercial_terms', 'term_id'],
        'repasse_commercial_reviews' => ['repasse_commercial_terms', 'term_id'],
        'repasse_commercial_review_events' => ['repasse_commercial_reviews', 'review_id'],
        'repasse_preparation_records' => ['repasse_cases', 'repasse_case_id'],
        'repasse_obligation_proposals' => ['repasse_cases', 'repasse_case_id'],
        'repasse_external_transfer_reports' => ['repasse_cases', 'repasse_case_id'],
        'repasse_payment_plans' => ['repasse_cases', 'repasse_case_id'],
        'repasse_plan_payments' => ['repasse_payment_plans', 'plan_id'],
        'repasse_plan_events' => ['repasse_payment_plans', 'plan_id'],
    ];

    public static function table(string $table): Builder
    {
        $name = preg_split('/\s+(?:as\s+)?/i', trim($table));
        $base = $name[0];
        $alias = $name[count($name) - 1];
        $builder = DB::table($table);
        if (! TenantContext::active()) {
            // Permite apenas scripts/seeders em CLI fora do fluxo autenticado.
            if (! app()->runningInConsole()) {
                throw new LogicException('Faltou middleware tenant.active para '.$base);
            }

            return $builder;
        }

        return self::scope($builder, $base, $alias, TenantContext::id(), 0);
    }

    private static function scope(
        Builder $query, string $table, string $alias, int $tenant, int $depth
    ): Builder {
        if (in_array($table, self::ROOTS, true)) {
            return $query->where($alias.'.tenant_id', $tenant);
        }
        $parent = self::PARENTS[$table] ?? null;
        if (! $parent) {
            throw new LogicException('Mapa de isolamento por empresa ausente: '.$table);
        }
        [$parentTable, $fk] = $parent;
        $parentAlias = 'tenant_root_'.$depth;
        return $query->whereExists(function (Builder $sub) use (
            $alias, $fk, $parentTable, $parentAlias, $tenant, $depth
        ): void {
            $sub->selectRaw('1')
                ->from($parentTable.' as '.$parentAlias)
                ->whereColumn($parentAlias.'.id', $alias.'.'.$fk);
            self::scope($sub, $parentTable, $parentAlias, $tenant, $depth + 1);
        });
    }
}
