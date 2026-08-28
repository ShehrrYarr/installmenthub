<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('agreement_number');
            $table->enum('status', ['draft', 'pending_approval', 'active', 'completed', 'defaulted', 'cancelled'])
                ->default('draft');

            // EMI calculator inputs/outputs — see App\Support\EmiCalculator.
            // financed = (product_price - down_payment) + processing_fee
            // total_interest = financed * (interest_rate / 100) * (duration_months / 12)
            // total_payable = financed + total_interest
            // monthly_installment = total_payable / duration_months
            $table->decimal('product_price', 14, 2);
            $table->decimal('down_payment', 14, 2)->default(0);
            $table->decimal('processing_fee', 14, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->unsignedTinyInteger('duration_months');
            $table->decimal('financed_amount', 14, 2);
            $table->decimal('total_interest', 14, 2);
            $table->decimal('total_payable', 14, 2);
            $table->decimal('monthly_installment', 14, 2);

            $table->date('start_date')->nullable();
            $table->date('first_due_date')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['shop_id', 'agreement_number']);
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
