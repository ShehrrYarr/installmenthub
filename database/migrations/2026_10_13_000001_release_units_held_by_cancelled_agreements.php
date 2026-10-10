<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling an agreement used to leave its serial/IMEI units marked "sold"
 * for good. Cancelling now puts them back in stock; this does the same for
 * the agreements that were cancelled before that.
 *
 * Deliberately narrow: a unit is only released if it is still marked sold, sat
 * on a cancelled agreement, is not on any live agreement, and was never rung
 * up over the counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('product_serials')
            ->where('status', 'sold')
            ->whereIn('id', function ($query) {
                $query->select('agreement_items.product_serial_id')
                    ->from('agreement_items')
                    ->join('agreements', 'agreements.id', '=', 'agreement_items.agreement_id')
                    ->where('agreements.status', 'cancelled')
                    ->whereNotNull('agreement_items.product_serial_id');
            })
            ->whereNotIn('id', function ($query) {
                $query->select('agreement_items.product_serial_id')
                    ->from('agreement_items')
                    ->join('agreements', 'agreements.id', '=', 'agreement_items.agreement_id')
                    ->where('agreements.status', '!=', 'cancelled')
                    ->whereNotNull('agreement_items.product_serial_id');
            })
            ->whereNotIn('id', function ($query) {
                $query->select('product_serial_id')
                    ->from('sale_items')
                    ->whereNotNull('product_serial_id');
            })
            ->update(['status' => 'in_stock', 'sold_at' => null]);
    }

    public function down(): void
    {
        // Nothing to undo: which units were released isn't recorded, and
        // marking them sold again would put the bug back.
    }
};
