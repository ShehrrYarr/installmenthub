<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agreement_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')->nullable()->after('product_serial_id')
                ->constrained('purchase_order_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agreement_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });
    }
};
