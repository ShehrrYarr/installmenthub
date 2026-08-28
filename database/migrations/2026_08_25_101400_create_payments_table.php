<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('agreements')->restrictOnDelete();
            $table->foreignId('installment_schedule_id')->nullable()
                ->constrained('installment_schedules')->nullOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();

            $table->decimal('amount', 14, 2);
            $table->enum('payment_mode', ['cash', 'bank', 'easypaisa', 'jazzcash', 'other'])->default('cash');
            $table->string('reference_number')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_number');

            $table->timestamp('paid_at');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'receipt_number']);
            $table->index(['shop_id', 'agreement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
