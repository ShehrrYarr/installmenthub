<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which blocks appear on an agreement's printable invoice (Settings →
     * Invoice) — every shop starts with everything on; a Shop Admin opts
     * sections out rather than in. See AgreementInvoiceController and
     * resources/views/invoices/agreement.blade.php.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('invoice_show_letterhead')->default(true)->after('theme');
            $table->boolean('invoice_show_agreement_info')->default(true)->after('invoice_show_letterhead');
            $table->boolean('invoice_show_customer_info')->default(true)->after('invoice_show_agreement_info');
            $table->boolean('invoice_show_product_items')->default(true)->after('invoice_show_customer_info');
            $table->boolean('invoice_show_financial_summary')->default(true)->after('invoice_show_product_items');
            $table->boolean('invoice_show_guarantors')->default(true)->after('invoice_show_financial_summary');
            $table->boolean('invoice_show_salesman')->default(true)->after('invoice_show_guarantors');
            $table->boolean('invoice_show_emi_schedule')->default(true)->after('invoice_show_salesman');
            $table->boolean('invoice_show_terms')->default(true)->after('invoice_show_emi_schedule');
            $table->boolean('invoice_show_notes')->default(true)->after('invoice_show_terms');
            $table->text('invoice_terms_text')->nullable()->after('invoice_show_notes');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_show_letterhead',
                'invoice_show_agreement_info',
                'invoice_show_customer_info',
                'invoice_show_product_items',
                'invoice_show_financial_summary',
                'invoice_show_guarantors',
                'invoice_show_salesman',
                'invoice_show_emi_schedule',
                'invoice_show_terms',
                'invoice_show_notes',
                'invoice_terms_text',
            ]);
        });
    }
};
