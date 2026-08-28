<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasBoundedMediaCollections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Vendor extends Model implements HasMedia
{
    use BelongsToShop, HasBoundedMediaCollections, HasFactory, InteractsWithMedia, SoftDeletes;

    public const MEDIA_COLLECTION = 'vendor_documents';

    public const MAX_MEDIA_FILES = 5;

    protected $fillable = [
        'shop_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'bank_name',
        'bank_account_title',
        'bank_account_number',
        'tax_number',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(VendorLedgerEntry::class);
    }

    /**
     * Logo, Trade License, Owner CNIC, Contract Copy, Storefront — 5 documents max.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
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
