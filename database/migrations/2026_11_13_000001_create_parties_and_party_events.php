<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 180);
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('name');
        });

        Schema::create('party_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['party_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_events');
        Schema::dropIfExists('parties');
    }
};
