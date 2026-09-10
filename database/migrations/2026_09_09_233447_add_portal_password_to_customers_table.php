<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Null until the shop gives this customer portal access — which is
            // also how "portal disabled" is represented, since a null hash can
            // never match a submitted password.
            $table->string('password')->nullable()->after('email');
            $table->timestamp('portal_last_login_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['password', 'portal_last_login_at']);
        });
    }
};
