<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementItem extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'agreement_id',
        'product_id',
        'product_serial_id',
        'purchase_order_item_id',
        'quantity',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * Items whose units are still out of the shop's stock - everything except
     * what sat on a cancelled agreement, which has gone back on the shelf.
     * Every stock sum over agreement items goes through this, so a cancelled
     * agreement can never leave stock short.
     */
    public function scopeHoldingStock(Builder $query): Builder
    {
        return $query->whereHas('agreement', fn ($agreements) => $agreements->where('status', '!=', Agreement::STATUS_CANCELLED));
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productSerial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * The purchase batch this unit actually came from — either linked
     * directly (non-serialized products, picked at sale time) or via the
     * serial number's own purchase-order-item (serialized products).
     */
    public function sourceBatch(): ?PurchaseOrderItem
    {
        return $this->purchase_order_item_id
            ? $this->purchaseOrderItem
            : $this->productSerial?->purchaseOrderItem;
    }

    /**
     * What this specific unit actually cost, falling back to the product's
     * manually-set cost price when it isn't linked to a purchase batch
     * (e.g. stock added before this tracking existed).
     */
    public function costPrice(): string
    {
        return (string) ($this->sourceBatch()?->cost_price ?? $this->product->cost_price);
    }

    public function vendorName(): ?string
    {
        return $this->sourceBatch()?->purchaseOrder?->vendor?->name;
    }
}
