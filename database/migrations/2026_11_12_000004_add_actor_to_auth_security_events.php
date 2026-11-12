<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_security_events', function (Blueprint $table): void {
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auth_security_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_user_id');
        });
    }
};
