<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('agreements')->cascadeOnDelete();

            $table->string('name');
            $table->string('cnic_number');
            $table->string('mobile_number');
            $table->string('relation');
            $table->text('work_address')->nullable();

            $table->timestamps();

            $table->index(['shop_id', 'agreement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarantors');
    }
};
