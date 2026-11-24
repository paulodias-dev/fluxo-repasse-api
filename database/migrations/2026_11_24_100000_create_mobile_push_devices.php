<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('device_uuid')->unique();
            $table->string('platform', 10);
            // Token encrypted with Laravel APP_KEY; never save plaintext or return it.
            $table->longText('encrypted_token')->nullable();
            $table->char('token_hash', 64)->nullable()->unique();
            $table->unsignedInteger('auth_version')->default(1);
            $table->timestamp('consented_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'tenant_id', 'revoked_at'], 'mobile_push_recipient_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_push_devices');
    }
};
