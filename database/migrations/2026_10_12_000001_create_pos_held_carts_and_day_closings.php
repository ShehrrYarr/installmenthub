<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sale parked while another customer is served. Nothing is
        // reserved — stock is only claimed when the sale is finally rung up.
        Schema::create('pos_held_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('label')->nullable();

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('walk_in_name')->nullable();
            $table->string('walk_in_phone', 30)->nullable();

            // The cashier's choices only (product, unit, quantity, discount) —
            // prices are always read from the catalog when it is resumed.
            $table->json('cart');
            $table->string('bill_discount_type', 10)->default('fixed');
            $table->string('bill_discount_value', 20)->default('');
            $table->text('notes')->nullable();

            $table->unsignedInteger('item_count')->default(0);
            // What it came to when parked — only to tell held sales apart on the list.
            $table->decimal('total_estimate', 14, 2)->default(0);

            $table->timestamp('held_at');
            $table->timestamps();

            $table->index(['shop_id', 'held_at']);
        });

        // End-of-day cash count, one per shop per day (re-saving a day's
        // count replaces it). The figures are what the system expected at
        // the moment of counting, kept so a shortage stays visible later.
        Schema::create('pos_day_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('business_date');

            $table->decimal('opening_float', 14, 2)->default(0);
            $table->decimal('cash_in', 14, 2)->default(0);
            $table->decimal('cash_refunds', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('counted_cash', 14, 2)->default(0);
            // counted − expected: negative is a shortage, positive an overage.
            $table->decimal('difference', 14, 2)->default(0);

            $table->json('summary')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['shop_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_day_closings');
        Schema::dropIfExists('pos_held_carts');
    }
};
