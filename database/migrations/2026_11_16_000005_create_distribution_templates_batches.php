<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_distribution_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->foreignId('source_party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->char('content_digest', 64);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['repasse_case_id', 'archived_at'], 'distribution_templates_case_status');
        });

        Schema::create('repasse_distribution_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('repasse_distribution_templates')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedSmallInteger('basis_points');
            $table->string('memo', 240)->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'party_id'], 'distribution_lines_unique_party');
        });

        Schema::create('repasse_distribution_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('repasse_distribution_templates')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('event_type', 40);
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['template_id', 'revision'], 'distribution_versions_unique_revision');
        });

        Schema::create('repasse_distribution_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')
                ->constrained('repasse_distribution_templates')->restrictOnDelete();
            $table->unsignedInteger('template_revision');
            $table->uuid('request_key')->unique();
            $table->char('payload_digest', 64);
            $table->unsignedSmallInteger('items_count');
            $table->json('template_snapshot');
            $table->json('results');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['template_id', 'id'], 'distribution_batches_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_distribution_batches');
        Schema::dropIfExists('repasse_distribution_versions');
        Schema::dropIfExists('repasse_distribution_lines');
        Schema::dropIfExists('repasse_distribution_templates');
    }
};
