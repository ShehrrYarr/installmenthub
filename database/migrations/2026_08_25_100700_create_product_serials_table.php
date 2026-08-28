<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()
                ->constrained('purchase_order_items')->nullOnDelete();

            // Real-world IMEIs/serials are globally unique across manufacturers,
            // so this is unique across shops, not just per-shop.
            $table->string('serial_number')->unique();
            $table->enum('status', ['in_stock', 'reserved', 'sold', 'returned', 'defective'])
                ->default('in_stock');
            $table->timestamp('sold_at')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_serials');
    }
};
