<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_case_partnerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();

            $table->unique(['tenant_id', 'repasse_case_id', 'party_id'], 'case_partnership_party_unique');
            $table->index(['tenant_id', 'repasse_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_partnerships');
    }
};
