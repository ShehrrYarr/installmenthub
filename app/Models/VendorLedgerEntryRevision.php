<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorLedgerEntryRevision extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'vendor_ledger_entry_id',
        'type',
        'amount',
        'payment_mode',
        'purchase_order_id',
        'manual_direction',
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
        return $this->belongsTo(VendorLedgerEntry::class, 'vendor_ledger_entry_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}
