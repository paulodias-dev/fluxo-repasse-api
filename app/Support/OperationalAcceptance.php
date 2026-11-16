<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only acceptance inventory for the six functional modules.
 *
 * Does NOT create a user, seed roles, migrate, rebuild tables, open files,
 * or change records. Its output contains aggregate counts, never identities
 * or credential values. In QA and production it provides an honest preflight,
 * not a certificate of test execution.
 */
final class OperationalAcceptance
{
    public const TABLES = [
        'identity' => [
            'users', 'roles', 'permissions', 'permission_role',
            'role_user', 'auth_security_events',
        ],
        'registry' => [
            'parties', 'party_events', 'repasse_case_parties',
        ],
        'vehicles' => [
            'repasse_cases', 'repasse_case_tasks', 'repasse_case_checklist',
            'repasse_case_notes', 'repasse_case_evidence',
        ],
        'finance' => [
            'repasse_case_financial_entries', 'repasse_financial_categories',
            'repasse_case_financial_documents', 'repasse_case_financial_events',
        ],
        'documentary' => [
            'repasse_scenarios', 'repasse_scenario_allocations',
            'repasse_scenario_versions', 'repasse_distribution_templates',
            'repasse_distribution_lines', 'repasse_distribution_batches',
            'repasse_scenario_reviews', 'repasse_scenario_review_events',
            'repasse_case_packages',
        ],
    ];

    public const COLUMNS = [
        'users' => ['is_active', 'auth_version'],
        'repasse_cases' => [
            'status', 'plate', 'brand', 'model',
            'purchase_amount_cents', 'sale_amount_cents',
        ],
        'repasse_case_evidence' => ['storage_path', 'sha256', 'archived_at'],
        'repasse_case_financial_entries' => [
            'amount_cents', 'category_id', 'replaces_entry_id', 'void_reason',
        ],
        'repasse_scenarios' => ['revision', 'current_digest'],
        'repasse_scenario_reviews' => [
            'scenario_revision', 'scenario_digest', 'status', 'assigned_to',
        ],
        'repasse_case_packages' => ['manifest_sha256', 'request_key'],
    ];

    public const REQUIRED_MIGRATIONS = [
        '2026_11_16_000008_backfill_existing_platform_admin_modules',
        '2026_11_16_000009_add_vehicle_360_profile_and_financial_entries',
        '2026_11_16_000010_create_vehicle_finance_workspace',
    ];

    public function inspect(): array
    {
        $checks = [];
        $data = [
            'database_connected' => false,
            'table_groups' => [],
            'missing_columns' => [],
            'pending_migrations' => [],
            'missing_permissions' => [],
            'active_admin_count' => 0,
            'module_tables_ready' => false,
        ];

        try {
            DB::connection()->getPdo();
            $data['database_connected'] = true;
            $checks[] = ['id' => 'database_connection', 'ok' => true];
        } catch (Throwable) {
            $checks[] = ['id' => 'database_connection', 'ok' => false];
            return $this->result($checks, $data);
        }

        foreach (self::TABLES as $name => $tables) {
            $missing = [];
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) $missing[] = $table;
            }
            $data['table_groups'][$name] = [
                'ready' => $missing === [], 'missing_tables' => $missing,
                'expected_tables' => count($tables),
            ];
            $checks[] = ['id' => 'schema_'.$name, 'ok' => $missing === []];
        }
        $data['module_tables_ready'] = collect($data['table_groups'])
            ->every(static fn (array $group): bool => $group['ready']);

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $data['missing_columns'][$table] = $columns;
                continue;
            }
            $missing = array_values(array_filter($columns,
                static fn (string $column): bool => ! Schema::hasColumn($table, $column)
            ));
            if ($missing !== []) $data['missing_columns'][$table] = $missing;
        }
        $checks[] = ['id' => 'required_columns',
            'ok' => $data['missing_columns'] === []];

        if (Schema::hasTable('migrations')) {
            $applied = DB::table('migrations')
                ->whereIn('migration', self::REQUIRED_MIGRATIONS)
                ->pluck('migration')->all();
            $data['pending_migrations'] = array_values(array_diff(
                self::REQUIRED_MIGRATIONS, $applied
            ));
        } else {
            $data['pending_migrations'] = self::REQUIRED_MIGRATIONS;
        }
        $checks[] = ['id' => 'critical_migrations',
            'ok' => $data['pending_migrations'] === []];

        $permissionTables = Schema::hasTable('permissions')
            && Schema::hasTable('roles') && Schema::hasTable('permission_role');
        if ($permissionTables) {
            $granted = DB::table('permissions')->pluck('key')->all();
            $data['missing_permissions'] = array_values(array_diff(
                OperationalAccessCatalog::keys(), $granted
            ));
            $adminRole = DB::table('roles')->where('slug', 'platform_admin')
                ->value('id');
            $adminGrants = $adminRole
                ? DB::table('permission_role as rp')
                    ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                    ->where('rp.role_id', $adminRole)->pluck('p.key')->all()
                : [];
            $data['missing_admin_grants'] = array_values(array_diff(
                OperationalAccessCatalog::keys(), $adminGrants
            ));
            $checks[] = ['id' => 'permission_catalog', 'ok' =>
                $data['missing_permissions'] === []
                && $data['missing_admin_grants'] === []];

            if ($adminRole && Schema::hasTable('role_user') && Schema::hasTable('users')) {
                $data['active_admin_count'] = DB::table('role_user as ru')
                    ->join('users as u', 'u.id', '=', 'ru.user_id')
                    ->where('ru.role_id', $adminRole)->where('u.is_active', true)
                    ->count();
            }
        } else {
            $data['missing_permissions'] = OperationalAccessCatalog::keys();
            $data['missing_admin_grants'] = OperationalAccessCatalog::keys();
            $checks[] = ['id' => 'permission_catalog', 'ok' => false];
        }
        $checks[] = ['id' => 'active_administrator',
            'ok' => $data['active_admin_count'] > 0];

        $jwtBlacklisting = config('jwt.blacklist_enabled') === true;
        $checks[] = ['id' => 'jwt_revocation', 'ok' => $jwtBlacklisting];

        return $this->result($checks, $data);
    }

    private function result(array $checks, array $data): array
    {
        return [
            'schema' => 'fluxo-repasse-operational-acceptance-v1',
            'ready' => collect($checks)->every(
                static fn (array $check): bool => $check['ok']
            ),
            'checks' => $checks,
            'details' => $data,
            'mode' => 'read_only',
            'financial_payment_enabled' => false,
            'tested_runtime' => false,
            'note' => 'Diagnóstico estrutural, não substitui PHPUnit, MySQL, build React ou QA de navegador.',
        ];
    }
}
