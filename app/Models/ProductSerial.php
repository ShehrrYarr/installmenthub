<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductSerial extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'product_id',
        'purchase_order_item_id',
        'serial_number',
        'status',
        'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function agreementItem(): HasOne
    {
        return $this->hasOne(AgreementItem::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'in_stock';
    }
}
