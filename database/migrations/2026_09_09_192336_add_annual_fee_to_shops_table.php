<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // The agreed yearly platform fee. Sits alongside monthly_fee rather
            // than replacing it — the existing monthly/trial billing still works.
            // The end of the paid year is tracked on the existing (previously
            // unused) next_billing_date column.
            $table->decimal('annual_fee', 12, 2)->default(0)->after('monthly_fee');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('annual_fee');
        });
    }
};
