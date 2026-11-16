<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_case_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('request_digest', 64);
            $table->string('title', 180);
            $table->text('note')->nullable();
            $table->json('manifest');
            $table->char('manifest_sha256', 64);
            $table->unsignedSmallInteger('evidence_count');
            $table->unsignedSmallInteger('scenario_count');
            $table->unsignedSmallInteger('review_count');
            $table->unsignedBigInteger('total_evidence_bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['repasse_case_id', 'id'], 'case_packages_case_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_packages');
    }
};
