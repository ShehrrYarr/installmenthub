<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('agreements')->cascadeOnDelete();

            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');

            $table->decimal('opening_balance', 14, 2);
            $table->decimal('principal_component', 14, 2);
            $table->decimal('interest_component', 14, 2);
            $table->decimal('penalty_amount', 14, 2)->default(0);
            $table->decimal('total_due', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2);

            $table->enum('status', ['pending', 'paid', 'partial', 'overdue'])->default('pending');
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['agreement_id', 'installment_number']);
            $table->index(['shop_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_schedules');
    }
};
