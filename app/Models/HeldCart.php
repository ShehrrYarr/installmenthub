<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A register's cart set aside so another customer can be served — resumed
 * from the register by anyone in the shop.
 */
class HeldCart extends Model
{
    use BelongsToShop, HasFactory;

    protected $table = 'pos_held_carts';

    protected $fillable = [
        'shop_id',
        'held_by',
        'label',
        'customer_id',
        'walk_in_name',
        'walk_in_phone',
        'cart',
        'bill_discount_type',
        'bill_discount_value',
        'notes',
        'item_count',
        'total_estimate',
        'held_at',
    ];

    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'total_estimate' => 'decimal:2',
            'held_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** What the list calls it: the cashier's own label, else who it is for. */
    public function title(): string
    {
        return $this->label
            ?: ($this->customer?->full_name ?: ($this->walk_in_name ?: 'Walk-in'));
    }
}
