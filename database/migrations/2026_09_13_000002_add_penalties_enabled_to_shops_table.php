<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('penalties_enabled')->default(true)->after('penalty_type');
        });

        // A shop already sitting on a rate of 0 was charging nothing, so it
        // starts switched off — every shop keeps the behaviour it had.
        DB::table('shops')->where('penalty_rate', '<=', 0)->update(['penalties_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('penalties_enabled');
        });
    }
};
