<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasBoundedMediaCollections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use BelongsToShop, HasBoundedMediaCollections, HasFactory, InteractsWithMedia, SoftDeletes;

    public const MEDIA_COLLECTION = 'product_gallery';

    public const MAX_MEDIA_FILES = 5;

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'name',
        'sku',
        'category',
        'brand',
        'model',
        'description',
        'cost_price',
        'cash_price',
        'is_serialized',
        'stock_quantity',
        'reorder_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'cash_price' => 'decimal:2',
            'is_serialized' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class);
    }

    public function agreementItems(): HasMany
    {
        return $this->hasMany(AgreementItem::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * SQL for how many units can still be sold.
     *
     * Serialized products are counted by their in_stock units. A
     * non-serialized product's stock_quantity only ever goes *up* (when a
     * purchase order is received) — nothing decrements it when something is
     * sold — so what's left is the quantity received, less everything that
     * has since gone out on an agreement or over the counter.
     *
     * stock_quantity is cast to SIGNED so MySQL doesn't raise an
     * out-of-range error when the subtraction goes negative.
     */
    public static function availableQuantitySql(): string
    {
        return "(CASE WHEN products.is_serialized = 1
            THEN (SELECT COUNT(*) FROM product_serials
                  WHERE product_serials.product_id = products.id AND product_serials.status = 'in_stock')
            ELSE CAST(products.stock_quantity AS SIGNED)
                - COALESCE((SELECT SUM(agreement_items.quantity) FROM agreement_items WHERE agreement_items.product_id = products.id), 0)
                - COALESCE((SELECT SUM(sale_items.quantity) FROM sale_items WHERE sale_items.product_id = products.id), 0)
            END)";
    }

    /** Adds an `available_quantity` column — see availableQuantitySql(). */
    public function scopeWithAvailableQuantity(Builder $query): Builder
    {
        if (is_null($query->getQuery()->columns)) {
            $query->select($query->getModel()->getTable().'.*');
        }

        return $query->selectRaw(static::availableQuantitySql().' as available_quantity');
    }

    /** Products at or below their reorder level, counting what has actually been sold. */
    public function scopeLowOnStock(Builder $query): Builder
    {
        return $query->whereRaw(static::availableQuantitySql().' <= products.reorder_level');
    }

    /** Units that can still be sold right now. Never negative — an oversold product reads as zero. */
    public function availableQuantity(): int
    {
        if (array_key_exists('available_quantity', $this->attributes)) {
            return max(0, (int) $this->attributes['available_quantity']);
        }

        return max(0, (int) static::query()
            ->whereKey($this->getKey())
            ->selectRaw(static::availableQuantitySql().' as available_quantity')
            ->value('available_quantity'));
    }

    /**
     * Thumbnails, Serial Number tags, Unboxing/Condition shots — 5 photos max.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 150, 150)
            ->nonQueued();

        $this->addMediaConversion('preview')
            ->fit(Fit::Contain, 800, 800)
            ->nonQueued();
    }
}
