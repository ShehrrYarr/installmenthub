<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether installment #1 falls due on the agreement's start date or a
        // month after it. Defaults to 'same_month' so every existing shop keeps
        // today's behavior unchanged.
        Schema::table('shops', function (Blueprint $table) {
            $table->enum('emi_first_installment', ['same_month', 'next_month'])
                ->default('same_month')
                ->after('emi_price_basis');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('emi_first_installment');
        });
    }
};
