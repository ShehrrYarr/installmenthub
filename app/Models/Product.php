<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasBoundedMediaCollections;
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
