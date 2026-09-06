<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'purchase_order_id',
        'product_id',
        'quantity',
        'cost_price',
        'selling_cash_price',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_cash_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function agreementItems(): HasMany
    {
        return $this->hasMany(AgreementItem::class);
    }

    /**
     * How many units from this batch are still unsold. Only meaningful for
     * non-serialized products — serialized units are tracked individually
     * via ProductSerial.status instead. Sold quantity is never restored
     * (agreement cancellation doesn't reverse it, matching how a sold
     * ProductSerial is also never reverted to in_stock).
     */
    public function remainingQuantity(): int
    {
        return $this->quantity - $this->agreementItems()->sum('quantity');
    }
}
