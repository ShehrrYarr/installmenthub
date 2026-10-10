<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cash counted in the drawer at the end of a trading day, set against
 * what the sales said should be there.
 */
class DayClosing extends Model
{
    use BelongsToShop, HasFactory;

    protected $table = 'pos_day_closings';

    protected $fillable = [
        'shop_id',
        'business_date',
        'opening_float',
        'cash_in',
        'cash_refunds',
        'expected_cash',
        'counted_cash',
        'difference',
        'summary',
        'notes',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_float' => 'decimal:2',
            'cash_in' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'summary' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isShort(): bool
    {
        return bccomp((string) $this->difference, '0', 2) < 0;
    }

    public function isOver(): bool
    {
        return bccomp((string) $this->difference, '0', 2) > 0;
    }
}
