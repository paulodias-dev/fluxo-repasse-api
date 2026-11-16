<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->string('plate', 10)->nullable();
            $table->string('chassis', 30)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('purchase_location', 180)->nullable();
            $table->string('seller_name', 180)->nullable();
            $table->string('buyer_name', 180)->nullable();
            $table->date('purchased_on')->nullable();
            $table->date('delivered_on')->nullable();
            $table->date('sold_on')->nullable();
            $table->unsignedBigInteger('purchase_amount_cents')->nullable();
            $table->unsignedBigInteger('sale_amount_cents')->nullable();
            $table->index('plate');
            $table->index('chassis');
        });

        Schema::create('repasse_case_financial_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('category', 80);
            $table->string('description', 255);
            $table->unsignedBigInteger('amount_cents');
            $table->date('effective_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['repasse_case_id', 'voided_at', 'id'], 'case_fin_entries_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_financial_entries');
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropIndex(['plate']);
            $table->dropIndex(['chassis']);
            $table->dropColumn([
                'plate', 'chassis', 'brand', 'model', 'model_year', 'purchase_location',
                'seller_name', 'buyer_name', 'purchased_on', 'delivered_on', 'sold_on',
                'purchase_amount_cents', 'sale_amount_cents',
            ]);
        });
    }
};
