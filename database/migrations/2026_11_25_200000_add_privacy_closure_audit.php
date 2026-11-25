<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('privacy_closed_at')->nullable();
        });
        Schema::table('account_deletion_requests', function (Blueprint $table): void {
            $table->timestamp('profile_closed_at')->nullable();
            $table->string('policy_version', 80)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('account_deletion_requests', fn (Blueprint $table) => $table->dropColumn(['profile_closed_at', 'policy_version']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('privacy_closed_at'));
    }
};
