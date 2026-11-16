<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('related_party_id')->constrained('parties')->restrictOnDelete();
            $table->string('description', 180);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['party_id', 'related_party_id', 'description'], 'party_links_unique_pair_description');
            $table->index(['party_id', 'is_active']);
            $table->index(['related_party_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_links');
    }
};
