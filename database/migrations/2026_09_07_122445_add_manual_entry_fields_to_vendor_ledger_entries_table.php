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
        Schema::table('vendor_ledger_entries', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bank', 'easypaisa', 'jazzcash', 'other'])->nullable()->after('amount');
            $table->foreignId('purchase_order_id')->nullable()->after('reference_id')
                ->constrained('purchase_orders')->nullOnDelete();
            // Both "Cash In" (a refund/credit note from the vendor) and "Cash
            // Out" (a payment made to the vendor) reduce the balance owed, so
            // both are stored as `type: credit` — this column is what lets a
            // manual entry be edited later (or netted against a purchase
            // order's paid_amount) knowing which of the two it actually was.
            $table->enum('manual_direction', ['cash_in', 'cash_out'])->nullable()->after('purchase_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'manual_direction']);
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
