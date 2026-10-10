<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Super Admin switches POS on per shop (it's sold as an add-on), so it
        // starts off for every existing shop.
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('pos_enabled')->default(false)->after('is_active');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            // Sequential per shop, so receipts read S-000001, S-000002… — the
            // number staff quote when a customer calls about a sale.
            $table->unsignedInteger('sequence');
            $table->string('sale_number');

            // Null customer_id means a walk-in; the optional name/phone only
            // exist so the receipt can still be addressed (e.g. over WhatsApp).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('walk_in_name')->nullable();
            $table->string('walk_in_phone', 30)->nullable();

            $table->foreignId('sold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('completed');

            // subtotal is the sum of line prices before any discount; total is
            // what the customer actually owes after line and bill discounts.
            $table->decimal('subtotal', 14, 2);
            $table->decimal('line_discount_total', 14, 2)->default(0);
            $table->string('bill_discount_type', 10)->default('fixed');
            $table->decimal('bill_discount_value', 14, 2)->default(0);
            $table->decimal('bill_discount_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->unique(['shop_id', 'sequence']);
            $table->unique(['shop_id', 'sale_number']);
            $table->index(['shop_id', 'sold_at']);
            $table->index(['shop_id', 'customer_id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_serial_id')->nullable()->constrained('product_serials')->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();

            // Snapshots: a receipt keeps reading the way it was printed even
            // if the product is later renamed, repriced or archived.
            $table->string('product_name');
            $table->string('serial_number')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->string('discount_type', 10)->default('fixed');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2);
            $table->decimal('cost_price', 14, 2)->default(0);

            $table->timestamps();

            $table->index(['shop_id', 'product_id']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->decimal('amount', 14, 2);
            // Cash handed over, when it was more than amount — change is
            // tendered minus amount. Never counted as money received.
            $table->decimal('tendered', 14, 2)->nullable();
            $table->string('payment_mode', 20)->default('cash');
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['shop_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('pos_enabled');
        });
    }
};
