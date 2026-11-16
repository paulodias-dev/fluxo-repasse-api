<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->uuid('client_reference')->unique();
            $table->char('initial_digest', 64);
            $table->char('current_digest', 64);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->char('currency', 3)->default('BRL');
            $table->unsignedBigInteger('gross_amount_cents');
            $table->foreignId('source_party_id')->nullable()->constrained('parties')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('forked_from_scenario_id')->nullable()->constrained('repasse_scenarios')->restrictOnDelete();
            $table->unsignedInteger('forked_from_revision')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['repasse_case_id', 'archived_at']);
            $table->index(['updated_at', 'id']);
        });

        Schema::create('repasse_scenario_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_scenario_id')->constrained('repasse_scenarios')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('memo', 240)->nullable();
            $table->timestamps();
            $table->unique(['repasse_scenario_id', 'party_id'], 'scenario_allocations_unique_party');
            $table->index(['party_id', 'repasse_scenario_id']);
        });

        Schema::create('repasse_scenario_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_scenario_id')->constrained('repasse_scenarios')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('event_type', 40);
            $table->json('snapshot');
            $table->char('sha256', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['repasse_scenario_id', 'revision'], 'scenario_versions_unique_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_scenario_versions');
        Schema::dropIfExists('repasse_scenario_allocations');
        Schema::dropIfExists('repasse_scenarios');
    }
};
