<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Set only while a balance is owed — when the customer agreed to settle by.
            $table->date('due_date')->nullable()->after('amount_paid');

            // Value of goods taken back, and the part of it actually paid out
            // in cash/bank. A refund credited to the customer's ledger is not
            // in refunded_total — it stays on the ledger until they use it.
            $table->decimal('returned_total', 14, 2)->default(0)->after('amount_paid');
            $table->decimal('refunded_total', 14, 2)->default(0)->after('returned_total');

            $table->index(['shop_id', 'due_date']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            // Units taken back. Stock and batch figures count quantity minus
            // this, so a return restocks without rewriting what was sold.
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            // Every payment — the ones at checkout and any made later — gets
            // its own receipt, e.g. S-000012-P2.
            $table->string('receipt_number')->nullable()->after('reference_number');
            $table->unique(['shop_id', 'receipt_number']);
        });

        // Phase 1 payments predate receipt numbers.
        $counters = [];

        DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->orderBy('sale_payments.id')
            ->get(['sale_payments.id', 'sale_payments.sale_id', 'sales.sale_number'])
            ->each(function ($row) use (&$counters) {
                $counters[$row->sale_id] = ($counters[$row->sale_id] ?? 0) + 1;

                DB::table('sale_payments')->where('id', $row->id)
                    ->update(['receipt_number' => $row->sale_number.'-P'.$counters[$row->sale_id]]);
            });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();

            $table->unsignedInteger('sequence');
            $table->string('return_number');

            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();

            // total_value is what the goods were worth on this sale (after its
            // discounts). Of that, refund_amount went out in cash/bank and
            // credit_amount was left on the customer's ledger; whatever is
            // neither simply reduced the balance they still owed.
            $table->decimal('total_value', 14, 2);
            $table->decimal('refund_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);

            // cash | bank | credit (credit = left on the customer's ledger).
            // Named payment_mode so a bank refund can carry a slip like any
            // other bank movement (HasReceiptProof).
            $table->string('payment_mode', 20)->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('reference_number')->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->unique(['shop_id', 'sequence']);
            $table->unique(['shop_id', 'return_number']);
            $table->index(['shop_id', 'sale_id']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('amount', 14, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'receipt_number']);
            $table->dropColumn('receipt_number');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'due_date']);
            $table->dropColumn(['due_date', 'returned_total', 'refunded_total']);
        });
    }
};
