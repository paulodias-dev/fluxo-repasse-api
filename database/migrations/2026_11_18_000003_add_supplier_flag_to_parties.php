<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->boolean('is_supplier')->default(false)->after('type');
            $table->index(['is_supplier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropIndex('parties_is_supplier_is_active_index');
            $table->dropColumn('is_supplier');
        });
    }
};

