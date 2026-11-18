<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS: banco único, isolamento lógico obrigatório via tenant_id.
 * Migração exige banco vazio ou projeto de backfill auditado: NÃO associar
 * dados preexistentes silenciosamente a uma empresa.
 * Em desenvolvimento está autorizado migrate:fresh --seed.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
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

        // Usuários pertencem a uma única empresa neste MVP.
        // Os dicionários globais roles/permissions e suas pivots continuam
        // globais; papéis técnicos são templates de permissão, não dados de negócio.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->after('id')->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'is_active']);
        });

        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('tenant_id')->after('id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'id']);
            });
        }

        // Categoria pode ter o mesmo nome em duas empresas distintas.
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_kind_slug_unique');
            $table->unique(['tenant_id', 'kind', 'slug'], 'repasse_fin_category_tenant_kind_slug');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_tenant_kind_slug');
            $table->unique(['kind', 'slug'], 'repasse_fin_category_kind_slug_unique');
        });
        foreach (array_reverse(self::TENANT_TABLES) as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex(['tenant_id', 'id']);
                $table->dropColumn('tenant_id');
            });
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropColumn('tenant_id');
        });
        Schema::dropIfExists('tenants');
    }
};
