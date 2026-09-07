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
        // Snapshot of a vendor_ledger_entries row as it stood *before* an
        // edit — one row per edit, mirroring customer_ledger_entry_revisions.
        Schema::create('vendor_ledger_entry_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('vendor_ledger_entry_id')->constrained('vendor_ledger_entries')->cascadeOnDelete();

            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 14, 2);
            $table->enum('payment_mode', ['cash', 'bank', 'easypaisa', 'jazzcash', 'other'])->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->enum('manual_direction', ['cash_in', 'cash_out'])->nullable();
            $table->string('description')->nullable();
            $table->date('entry_date');

            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['vendor_ledger_entry_id', 'created_at'], 'vendor_ledger_entry_revisions_entry_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_ledger_entry_revisions');
    }
};
