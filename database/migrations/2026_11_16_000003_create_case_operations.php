<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_case_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('title', 180);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->date('due_on')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_on']);
            $table->index(['assigned_to', 'status']);
            $table->index(['repasse_case_id', 'status']);
        });

        Schema::create('repasse_case_checklist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('label', 200);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['repasse_case_id', 'checked_at']);
        });

        Schema::create('repasse_case_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'id']);
        });

        Schema::create('repasse_case_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_path', 500)->unique();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['repasse_case_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_evidence');
        Schema::dropIfExists('repasse_case_notes');
        Schema::dropIfExists('repasse_case_checklist');
        Schema::dropIfExists('repasse_case_tasks');
    }
};
