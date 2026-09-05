<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable — only manually-added entries (Cash In/Cash Out) capture
        // their own payment method; automatic entries (agreements, payments)
        // already have it on their linked Payment record instead.
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bank', 'easypaisa', 'jazzcash', 'other'])->nullable()->after('amount');
        });

        Schema::table('customer_ledger_entry_revisions', function (Blueprint $table) {
            $table->enum('payment_mode', ['cash', 'bank', 'easypaisa', 'jazzcash', 'other'])->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('customer_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });

        Schema::table('customer_ledger_entry_revisions', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });
    }
};
