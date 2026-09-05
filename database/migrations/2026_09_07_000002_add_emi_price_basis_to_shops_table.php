<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of the product's two prices the EMI calculator auto-fills
        // "Product Price" with when creating an agreement. Defaults to
        // 'selling' so every existing shop keeps today's behavior unchanged.
        Schema::table('shops', function (Blueprint $table) {
            $table->enum('emi_price_basis', ['cost', 'selling'])->default('selling')->after('default_processing_fee');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('emi_price_basis');
        });
    }
};
