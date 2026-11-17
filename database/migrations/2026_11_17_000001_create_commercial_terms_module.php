<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_commercial_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('template_id')
                ->constrained('repasse_distribution_templates')->restrictOnDelete();
            $table->unsignedInteger('template_revision');
            $table->char('template_sha256', 64);
            $table->json('template_snapshot');
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('reference_cents')->nullable();
            $table->json('conditions');
            $table->unsignedInteger('revision')->default(1);
            $table->char('content_sha256', 64);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['repasse_case_id', 'archived_at', 'id'], 'terms_case_status_idx');
            $table->index(['template_id', 'template_revision'], 'terms_template_rev_idx');
        });

        Schema::create('repasse_commercial_term_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_id')->constrained('repasse_commercial_terms')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('event_type', 40);
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['term_id', 'revision'], 'terms_versions_unique_revision');
        });

        Schema::create('repasse_commercial_term_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('term_id')->constrained('repasse_commercial_terms')->restrictOnDelete();
            $table->foreignId('evidence_id')->constrained('repasse_case_evidence')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['term_id', 'evidence_id'], 'terms_evidence_unique_pair');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_commercial_term_evidence');
        Schema::dropIfExists('repasse_commercial_term_versions');
        Schema::dropIfExists('repasse_commercial_terms');
    }
};
