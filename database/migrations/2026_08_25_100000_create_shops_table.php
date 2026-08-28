<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // No FK constraint here yet — users.shop_id doesn't exist until the next
            // migration, and users.id doesn't exist as a target until after that.
            // See add_owner_foreign_to_shops_table for the constraint.
            $table->unsignedBigInteger('owner_user_id')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('currency_code', 3)->default('PKR');
            $table->string('timezone', 64)->default('Asia/Karachi');

            $table->enum('subscription_status', ['trial', 'active', 'suspended'])->default('trial');
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->decimal('monthly_fee', 12, 2)->default(0);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_started_at')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // EMI calculator defaults, editable per shop
            $table->decimal('default_interest_rate', 5, 2)->default(0);
            $table->decimal('default_processing_fee', 12, 2)->default(0);

            // Overdue penalty configuration
            $table->enum('penalty_type', ['daily', 'fixed'])->default('daily');
            $table->decimal('penalty_rate', 10, 2)->default(0);
            $table->unsignedTinyInteger('grace_period_days')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
