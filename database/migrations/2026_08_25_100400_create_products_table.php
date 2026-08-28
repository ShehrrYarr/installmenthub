<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            $table->string('name');
            $table->string('sku')->nullable();
            $table->enum('category', ['mobile', 'laptop', 'ac', 'refrigerator', 'solar_inverter', 'other'])
                ->default('other');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->text('description')->nullable();

            $table->decimal('cost_price', 14, 2)->default(0);
            $table->decimal('cash_price', 14, 2)->default(0);

            // Serialized electronics (mobiles, laptops, ACs, fridges, inverters) require
            // IMEI/serial capture on every inbound unit — see product_serials.
            $table->boolean('is_serialized')->default(true);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'sku']);
            $table->index(['shop_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
