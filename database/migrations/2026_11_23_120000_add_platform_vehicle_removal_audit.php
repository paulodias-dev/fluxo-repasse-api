<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->timestamp('admin_deleted_at')->nullable()->after('updated_at');
            $table->foreignId('admin_deleted_by')->nullable()->after('admin_deleted_at')
                ->constrained('users')->nullOnDelete();
            $table->text('admin_deletion_reason')->nullable()->after('admin_deleted_by');
            $table->index(['admin_deleted_at', 'tenant_id'], 'repasse_cases_admin_deleted_idx');
        });

        Schema::table('repasse_case_events', function (Blueprint $table): void {
            $table->json('details')->nullable()->after('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_case_events', function (Blueprint $table): void {
            $table->dropColumn('details');
        });

        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropIndex('repasse_cases_admin_deleted_idx');
            $table->dropConstrainedForeignId('admin_deleted_by');
            $table->dropColumn(['admin_deleted_at', 'admin_deletion_reason']);
        });
    }
};
