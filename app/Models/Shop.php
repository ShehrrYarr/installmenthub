<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
        'phone',
        'email',
        'address',
        'city',
        'currency_code',
        'timezone',
        'subscription_status',
        'billing_cycle',
        'monthly_fee',
        'annual_fee',
        'trial_ends_at',
        'subscription_started_at',
        'next_billing_date',
        'suspended_at',
        'suspension_reason',
        'default_interest_rate',
        'default_processing_fee',
        'emi_price_basis',
        'penalty_type',
        'penalty_rate',
        'grace_period_days',
        'is_active',
        'theme',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_started_at' => 'datetime',
            'next_billing_date' => 'date',
            'suspended_at' => 'datetime',
            'monthly_fee' => 'decimal:2',
            'annual_fee' => 'decimal:2',
            'default_interest_rate' => 'decimal:2',
            'default_processing_fee' => 'decimal:2',
            'penalty_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Shops are addressed by slug in URLs (each shop gets its own path,
     * e.g. /s/{slug}/...) rather than by numeric id.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(ShopSubscriptionPayment::class);
    }

    /**
     * The subscription year a payment made right now would buy.
     *
     * The anniversary is fixed: a new year always starts where the last one
     * ended, so paying late costs the shop the lapsed time rather than
     * pushing the renewal date later. If a shop lapsed for more than a year,
     * whole years are rolled forward so the period being paid for is the
     * current one — otherwise it would expire again the moment it reopened.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    public function nextSubscriptionPeriod(): array
    {
        $start = $this->next_billing_date?->copy() ?? today();

        while ($start->copy()->addYear()->isPast()) {
            $start->addYear();
        }

        return [$start, $start->copy()->addYear()];
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function isOnTrial(): bool
    {
        return $this->subscription_status === 'trial';
    }

    public function isSuspended(): bool
    {
        return $this->subscription_status === 'suspended';
    }
}
