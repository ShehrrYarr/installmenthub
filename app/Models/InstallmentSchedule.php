<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentSchedule extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'agreement_id',
        'installment_number',
        'due_date',
        'opening_balance',
        'principal_component',
        'interest_component',
        'penalty_amount',
        'total_due',
        'amount_paid',
        'closing_balance',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'opening_balance' => 'decimal:2',
            'principal_component' => 'decimal:2',
            'interest_component' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'total_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'closing_balance' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }

    public function balanceRemaining(): string
    {
        return bcsub((string) $this->total_due, (string) $this->amount_paid, 2);
    }
}
