<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription payment a shop made to the platform. Not tenant-scoped —
 * this is platform billing, managed only from the Super Admin panel.
 */
class ShopSubscriptionPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'amount',
        'paid_on',
        'period_start',
        'period_end',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
