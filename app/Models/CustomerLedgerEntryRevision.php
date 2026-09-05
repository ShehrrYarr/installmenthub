<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLedgerEntryRevision extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'customer_ledger_entry_id',
        'type',
        'amount',
        'payment_mode',
        'agreement_id',
        'description',
        'entry_date',
        'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CustomerLedgerEntry::class, 'customer_ledger_entry_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
