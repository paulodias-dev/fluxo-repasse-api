<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_case_checklist_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('title', 120);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'repasse_case_id', 'id'], 'case_checklist_sets_lookup');
        });

        Schema::table('repasse_case_checklist', function (Blueprint $table): void {
            $table->foreignId('checklist_set_id')->nullable()->constrained('repasse_case_checklist_sets')->restrictOnDelete();
            $table->string('group_name', 40)->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'checklist_set_id', 'id'], 'case_checklist_items_lookup');
        });

        Schema::table('repasse_case_tasks', function (Blueprint $table): void {
            $table->string('kind', 20)->default('task');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('remind_minutes')->nullable();
            $table->index(['tenant_id', 'due_on', 'kind'], 'case_task_calendar_lookup');
        });

        Schema::create('repasse_case_task_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('task_id')->constrained('repasse_case_tasks')->restrictOnDelete();
            $table->foreignId('evidence_id')->constrained('repasse_case_evidence')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'task_id', 'evidence_id'], 'case_task_document_unique');
            $table->index(['tenant_id', 'repasse_case_id'], 'case_task_document_case_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_task_documents');
        Schema::table('repasse_case_tasks', function (Blueprint $table): void {
            $table->dropIndex('case_task_calendar_lookup');
            $table->dropColumn(['kind', 'start_time', 'end_time', 'remind_minutes']);
        });
        Schema::table('repasse_case_checklist', function (Blueprint $table): void {
            $table->dropIndex('case_checklist_items_lookup');
            $table->dropConstrainedForeignId('checklist_set_id');
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['group_name', 'due_on', 'assigned_to']);
        });
        Schema::dropIfExists('repasse_case_checklist_sets');
    }
};
