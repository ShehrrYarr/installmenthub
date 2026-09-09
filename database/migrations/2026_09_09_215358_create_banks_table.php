<?php

use App\Models\Shop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The fixed options every shop had before banks became configurable. */
    private const LEGACY_METHODS = [
        'bank' => 'Bank',
        'easypaisa' => 'EasyPaisa',
        'jazzcash' => 'JazzCash',
        'other' => 'Other',
    ];

    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();

            $table->string('name');
            $table->string('account_title')->nullable();
            $table->string('account_number')->nullable();
            $table->string('branch')->nullable();

            // Deactivated banks drop out of the payment dropdowns but stay
            // readable on the records that already reference them.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
        });

        // Seed each existing shop with the methods it already had, so nothing
        // that was selectable before this change stops being selectable.
        // Records already written keep their own payment_mode text and are
        // unaffected either way.
        $now = now();

        foreach (Shop::withTrashed()->pluck('id') as $shopId) {
            DB::table('banks')->insert(
                collect(self::LEGACY_METHODS)->map(fn ($name) => [
                    'shop_id' => $shopId,
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
