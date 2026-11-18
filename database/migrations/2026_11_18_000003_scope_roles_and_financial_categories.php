<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Papéis são exclusivos da empresa; permissões (catálogo de chaves)
        // continuam globais. Edição de papel jamais altera direitos de outra.
        Schema::table('roles', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'id']);
        });
        $initial = DB::table('tenants')->where('slug', 'empresa-inicial')->value('id');
        if ($initial) {
            DB::table('roles')->update(['tenant_id' => $initial]);
        }
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
        });
        DB::table('repasse_financial_categories')->update(['tenant_id' => $initial]);
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_kind_slug_unique');
            $table->unique(['tenant_id', 'kind', 'slug'], 'repasse_fin_category_tenant_slug_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('tenants')->count() > 1) {
            throw new RuntimeException('Rollback negado: existem categorias/papéis de outras empresas.');
        }
        Schema::table('repasse_financial_categories', function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_tenant_slug_unique');
            $table->unique(['kind', 'slug'], 'repasse_fin_category_kind_slug_unique');
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
