<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class SaleItem extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'product_id',
        'product_serial_id',
        'purchase_order_item_id',
        'product_name',
        'serial_number',
        'quantity',
        'returned_quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'line_total',
        'cost_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    /**
     * What is still out with the customer: sold, less taken back. Every stock
     * and batch figure counts this rather than `quantity`, so a return
     * restocks without rewriting what was sold.
     */
    public static function netQuantityExpression(): Expression
    {
        return DB::raw('sale_items.quantity - sale_items.returned_quantity');
    }

    public function returnableQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productSerial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
