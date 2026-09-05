<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Snapshot of a customer_ledger_entries row as it stood *before* an
        // edit — one row per edit, so the full history of a manually-added
        // entry can be reconstructed alongside its current (live) state.
        Schema::create('customer_ledger_entry_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('customer_ledger_entry_id')->constrained('customer_ledger_entries')->cascadeOnDelete();

            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 14, 2);
            $table->foreignId('agreement_id')->nullable()->constrained('agreements')->nullOnDelete();
            $table->string('description')->nullable();
            $table->date('entry_date');

            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_ledger_entry_id', 'created_at'], 'ledger_entry_revisions_entry_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_ledger_entry_revisions');
    }
};
