<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['status', 'requested_at']);
        });
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->text('apple_refresh_ciphertext')->nullable();
            $table->timestamp('apple_revoked_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('social_accounts', fn (Blueprint $table) => $table->dropColumn(['apple_refresh_ciphertext', 'apple_revoked_at']));
        Schema::dropIfExists('account_deletion_requests');
    }
};
