<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table that records how money moved. The existing payment_mode
     * enum is deliberately left untouched: rows written before this change
     * keep rendering from it, and new rows store 'bank' there plus the
     * chosen bank here. Nothing needs backfilling.
     */
    private const TABLES = [
        'payments',
        'expenses',
        'purchase_orders',
        'customer_ledger_entries',
        'customer_ledger_entry_revisions',
        'vendor_ledger_entries',
        'vendor_ledger_entry_revisions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('bank_id')->nullable()->after('payment_mode')
                    ->constrained('banks')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('bank_id');
            });
        }
    }
};
