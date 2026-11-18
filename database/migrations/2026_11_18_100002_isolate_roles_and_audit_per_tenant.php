<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papéis funcionais não são partilhados entre empresas.
 * Eventos de segurança guardam tenant_id quando há usuário identificado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_slug_unique');
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'slug'], 'roles_tenant_slug_unique');
        });

        Schema::table('auth_security_events', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')
                ->constrained('tenants')->nullOnDelete();
            $table->index(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('auth_security_events', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'id']);
            $table->dropColumn('tenant_id');
        });

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique('roles_tenant_slug_unique');
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
            $table->unique('slug');
        });
    }
};
