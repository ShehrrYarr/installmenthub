<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a shop has paid the platform, one row per payment. This is
        // billing between the platform and its tenants, so it is deliberately
        // NOT shop-scoped (no ShopScope) — only a Super Admin ever reads it.
        Schema::create('shop_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->decimal('amount', 12, 2);
            $table->date('paid_on');

            // The subscription year this payment bought.
            $table->date('period_start');
            $table->date('period_end');

            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['shop_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_subscription_payments');
    }
};
