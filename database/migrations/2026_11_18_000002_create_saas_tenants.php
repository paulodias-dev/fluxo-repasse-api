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

        // A constraint composta evita associação entre casos e participantes
        // de empresas diferentes mesmo sob tentativa de escrita maliciosa.
        Schema::table('parties', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'parties_tenant_id_unique');
        });
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'cases_tenant_id_unique');
        });
        Schema::table('repasse_case_parties', function (Blueprint $table): void {
            $table->foreign(['tenant_id', 'repasse_case_id'], 'case_parties_tenant_case_fk')
                ->references(['tenant_id', 'id'])->on('repasse_cases')->restrictOnDelete();
            $table->foreign(['tenant_id', 'party_id'], 'case_parties_tenant_party_fk')
                ->references(['tenant_id', 'id'])->on('parties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repasse_case_parties', function (Blueprint $table): void {
            $table->dropForeign('case_parties_tenant_case_fk');
            $table->dropForeign('case_parties_tenant_party_fk');
        });
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropUnique('cases_tenant_id_unique');
        });
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropUnique('parties_tenant_id_unique');
        });
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
