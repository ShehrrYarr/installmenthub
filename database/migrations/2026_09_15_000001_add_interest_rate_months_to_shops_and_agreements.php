<?php

use App\Support\InterestPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stretch of time a flat interest rate covers. Defaults to 12 so
        // every existing shop and agreement keeps meaning exactly what it did
        // — the calculator divided the duration by 12 unconditionally.
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedSmallInteger('interest_rate_months')
                ->default(InterestPeriod::DEFAULT_MONTHS)
                ->after('default_interest_rate');
        });

        // Copied onto the agreement at creation, so a past agreement still
        // reads correctly after the shop switches its basis.
        Schema::table('agreements', function (Blueprint $table) {
            $table->unsignedSmallInteger('interest_rate_months')
                ->default(InterestPeriod::DEFAULT_MONTHS)
                ->after('interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('interest_rate_months');
        });

        Schema::table('agreements', function (Blueprint $table) {
            $table->dropColumn('interest_rate_months');
        });
    }
};
