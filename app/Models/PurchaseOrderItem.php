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
     * Agreement items that still hold units of this batch - the ones on a
     * cancelled agreement have gone back. Use this, not agreementItems(), for
     * anything that counts what is left.
     */
    public function heldAgreementItems(): HasMany
    {
        return $this->hasMany(AgreementItem::class)->holdingStock();
    }

    /** Units from this batch sold over the counter (POS). */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * How many units from this batch are still unsold. Only meaningful for
     * non-serialized products — serialized units are tracked individually
     * via ProductSerial.status instead. Units on a cancelled agreement are
     * counted as unsold again.
     */
    public function remainingQuantity(): int
    {
        return $this->quantity
            - (int) $this->heldAgreementItems()->sum('quantity')
            - (int) $this->saleItems()->sum(SaleItem::netQuantityExpression());
    }
}
