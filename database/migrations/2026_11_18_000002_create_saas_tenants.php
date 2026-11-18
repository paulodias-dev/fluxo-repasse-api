<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marco de instalação LIMPA do SaaS, após migrations existentes.
 * A base usa um único MySQL. Todas as tabelas de domínio recebem tenant_id.
 * Usuários da plataforma / permissões técnicas podem ser globais, mas não
 * acessam negócio sem tenant efetivo.
 */
return new class extends Migration
{
    private const OWNED = [
        'users', 'roles', 'role_user', 'permission_role',
        'parties', 'party_events', 'party_links',
        'repasse_cases', 'repasse_case_parties', 'repasse_case_events',
        'repasse_case_tasks', 'repasse_case_checklist',
        'repasse_case_notes', 'repasse_case_evidence',
        'repasse_scenarios', 'repasse_scenario_allocations',
        'repasse_scenario_versions', 'repasse_distribution_templates',
        'repasse_distribution_lines', 'repasse_distribution_versions',
        'repasse_distribution_batches', 'repasse_scenario_reviews',
        'repasse_scenario_review_events', 'repasse_case_packages',
        'repasse_case_financial_entries', 'repasse_financial_categories',
        'repasse_case_financial_documents', 'repasse_case_financial_events',
        'repasse_commercial_terms', 'repasse_commercial_term_versions',
        'repasse_commercial_term_evidence', 'repasse_commercial_reviews',
        'repasse_commercial_review_events', 'repasse_preparation_records',
        'repasse_obligation_proposals', 'repasse_external_transfer_reports',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::OWNED as $name) {
            // Instalação limpa: nullable apenas para compatibilidade com
            // platform_admin (users, roles, pivôs); domínio nunca pode ser null.
            $globalIdentity = in_array($name, ['users', 'roles', 'role_user', 'permission_role'], true);
            Schema::table($name, function (Blueprint $table) use ($name, $globalIdentity): void {
                $field = $table->foreignId('tenant_id');
                if ($globalIdentity) {
                    $field->nullable();
                }
                // Compatibilidade com fixtures antigas dos testes SQLite.
                // A instalação MySQL real NUNCA recebe tenant default.
                if (app()->environment('testing')) {
                    $field->default(1);
                }
                $field->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id'], 'tenant_scope_'.substr(hash('sha256', $name), 0, 12));
            });
        }

        // Não usar índice global de categorias, pois as mesmas categorias
        // pertencem a cada organização independentemente.
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_kind_slug_unique');
            $table->unique(['tenant_id', 'kind', 'slug'], 'fin_cat_tenant_kind_slug_unique');
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->unique(['tenant_id', 'slug'], 'roles_tenant_slug_unique');
        });

        // Todas as entidades com ID podem ser alvo de FKs compostas
        // (tenant_id, id). Isso impede referenciar registros de outra empresa,
        // inclusive arquivos, histórico, tarefas, beneficiários e usuários.
        foreach (self::OWNED as $name) {
            if (in_array($name, ['role_user', 'permission_role'], true)) continue;
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unique(['tenant_id', 'id'],
                    'tenant_key_'.substr(hash('sha256', $name), 0, 12));
            });
        }

        // Replica cada FK de negócio com o tenant da linha.
        // A FK original permanece; a composta acrescenta a barreira de tenant.
        if (DB::getDriverName() === 'mysql') foreach (self::OWNED as $name) {
            foreach (Schema::getForeignKeys($name) as $foreign) {
                $columns = $foreign['columns'] ?? [];
                $parent = $foreign['foreign_table'] ?? '';
                if (count($columns) !== 1 || ! in_array($parent, self::OWNED, true)
                    || $columns[0] === 'tenant_id'
                    || in_array($parent, ['role_user', 'permission_role'], true)) {
                    continue;
                }
                $column = $columns[0];
                $fkName = 'tenant_link_'.substr(hash('sha256', $name.'_'.$column), 0, 12);
                Schema::table($name, function (Blueprint $table) use ($column, $fkName, $parent): void {
                    $table->foreign(['tenant_id', $column], $fkName)
                        ->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // Remover primeiro todas as FKs compostas criadas acima.
        if (DB::getDriverName() === 'mysql') foreach (array_reverse(self::OWNED) as $name) {
            foreach (Schema::getForeignKeys($name) as $foreign) {
                $fkName = $foreign['name'] ?? '';
                if (str_starts_with($fkName, 'tenant_link_')) {
                    Schema::table($name, function (Blueprint $table) use ($fkName): void {
                        $table->dropForeign($fkName);
                    });
                }
            }
        }
        foreach (array_reverse(self::OWNED) as $name) {
            if (in_array($name, ['role_user', 'permission_role'], true)) continue;
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique('tenant_key_'.substr(hash('sha256', $name), 0, 12));
            });
        }
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_tenant_slug_unique');
            $table->unique('slug');
        });
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('fin_cat_tenant_kind_slug_unique');
            $table->unique(['kind', 'slug'], 'repasse_fin_category_kind_slug_unique');
        });
        foreach (array_reverse(self::OWNED) as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex('tenant_scope_'.substr(hash('sha256', $name), 0, 12));
                $table->dropConstrainedForeignId('tenant_id');
            });
        }
        Schema::dropIfExists('tenants');
    }
};
