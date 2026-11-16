<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'id']);
        });

        Schema::create('repasse_case_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('description', 180)->nullable();
            $table->timestamps();
            $table->unique(['repasse_case_id', 'party_id']);
        });

        Schema::create('repasse_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_events');
        Schema::dropIfExists('repasse_case_parties');
        Schema::dropIfExists('repasse_cases');
    }
};
