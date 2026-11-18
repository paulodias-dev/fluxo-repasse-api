<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('tenant_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('membership_role', 16)->default('member');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });
        Schema::create('tenant_role_user', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['tenant_id', 'user_id', 'role_id']);
        });
        Schema::table('parties', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'id']);
        });
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'status', 'id']);
        });

        // Migração não destrutiva do single-org: o legado inteiro passa à
        // Empresa inicial. Nenhum usuário/veículo é associado a outra empresa.
        $now = now();
        $tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Empresa inicial', 'slug' => 'empresa-inicial',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('parties')->update(['tenant_id' => $tenantId]);
        DB::table('repasse_cases')->update(['tenant_id' => $tenantId]);
        foreach (DB::table('users')->pluck('id') as $userId) {
            DB::table('tenant_users')->insert([
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'membership_role' => 'member', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (DB::table('role_user')->get(['user_id', 'role_id']) as $role) {
            DB::table('tenant_role_user')->insert([
                'tenant_id' => $tenantId, 'user_id' => $role->user_id,
                'role_id' => $role->role_id,
            ]);
        }
    }

    public function down(): void
    {
        if (DB::table('tenants')->count() > 1) {
            throw new RuntimeException('Rollback SaaS recusado: existem empresas adicionais.');
        }
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('tenant_role_user');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
