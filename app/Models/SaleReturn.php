<?php

namespace App\Models;

use App\Support\PaymentMethod;
use App\Traits\BelongsToShop;
use App\Traits\HasReceiptProof;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * Goods taken back from a counter sale — in full or in part — and what became
 * of the money for them.
 */
class SaleReturn extends Model implements HasMedia
{
    use BelongsToShop, HasFactory, HasReceiptProof;

    /** Refund left on the customer's ledger instead of being paid out. */
    public const MODE_CREDIT = 'credit';

    protected $fillable = [
        'shop_id',
        'sale_id',
        'sequence',
        'return_number',
        'returned_by',
        'total_value',
        'refund_amount',
        'credit_amount',
        'payment_mode',
        'bank_id',
        'reference_number',
        'notes',
        'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** "Cash", "Meezan Bank", "Left on ledger" — how the money for this return went back. */
    public function refundLabel(): ?string
    {
        return match (true) {
            $this->payment_mode === self::MODE_CREDIT => 'Credited to ledger',
            $this->payment_mode !== null => PaymentMethod::label($this->payment_mode, $this->bank),
            default => null,
        };
    }
}
