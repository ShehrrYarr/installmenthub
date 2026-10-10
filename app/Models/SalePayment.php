<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasReceiptProof;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;

class SalePayment extends Model implements HasMedia
{
    use BelongsToShop, HasFactory, HasReceiptProof;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'customer_id',
        'amount',
        'tendered',
        'payment_mode',
        'bank_id',
        'reference_number',
        'receipt_number',
        'received_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tendered' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Change handed back on a cash payment, or null when nothing beyond the amount was tendered. */
    public function changeGiven(): ?string
    {
        if ($this->tendered === null || bccomp((string) $this->tendered, (string) $this->amount, 2) <= 0) {
            return null;
        }

        return bcsub((string) $this->tendered, (string) $this->amount, 2);
    }
}
