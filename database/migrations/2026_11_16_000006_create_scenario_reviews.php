<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_scenario_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scenario_id')->constrained('repasse_scenarios')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_digest', 64);
            $table->unsignedInteger('scenario_revision');
            $table->char('scenario_digest', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('request_note');
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['scenario_id', 'scenario_revision', 'status'], 'scenario_review_version_status');
            $table->index(['status', 'due_on'], 'scenario_review_queue');
            $table->index(['assigned_to', 'status'], 'scenario_review_assignee');
        });

        Schema::create('repasse_scenario_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained('repasse_scenario_reviews')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('details')->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['review_id', 'sequence'], 'scenario_review_event_sequence');
            $table->index(['review_id', 'created_at'], 'scenario_review_event_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_scenario_review_events');
        Schema::dropIfExists('repasse_scenario_reviews');
    }
};
