<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasBoundedMediaCollections;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Also the authenticatable for the customer portal (guard: customer), which
 * is why this carries a password. Staff sign in as User on the web guard —
 * the two are entirely separate sessions.
 */
class Customer extends Model implements AuthenticatableContract, HasMedia
{
    use AuthenticatableTrait, BelongsToShop, HasBoundedMediaCollections, HasFactory, InteractsWithMedia, SoftDeletes;

    public const MEDIA_COLLECTION = 'customer_documents';

    public const MAX_MEDIA_FILES = 5;

    protected $fillable = [
        'shop_id',
        'first_name',
        'last_name',
        'cnic_number',
        'phone',
        'alternate_phone',
        'email',
        'address',
        'city',
        'occupation',
        'employer_name',
        'monthly_income',
        'home_ownership',
        'date_of_birth',
        'gender',
        'notes',
        'is_active',
        'password',
        'portal_last_login_at',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'portal_last_login_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * CNIC/ID Front, CNIC/ID Back, Customer Photo, Proof of Income, Guarantor Photo — 5 max.
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
