<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasReceiptProof;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model implements HasMedia
{
    use BelongsToShop, HasFactory, HasReceiptProof;

    /** Common categories offered in the UI — the column itself is a free string, so older/custom values still display fine. */
    public const CATEGORIES = ['Rent', 'Utilities', 'Salaries', 'Transport', 'Maintenance', 'Other'];

    protected $fillable = [
        'shop_id',
        'category',
        'amount',
        'expense_date',
        'description',
        'payment_mode',
        'bank_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
