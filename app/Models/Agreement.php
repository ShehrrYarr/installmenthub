<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agreement extends Model
{
    use BelongsToShop, HasFactory, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'salesman_id',
        'agreement_number',
        'status',
        'product_price',
        'down_payment',
        'processing_fee',
        'interest_rate',
        'duration_months',
        'financed_amount',
        'total_interest',
        'total_payable',
        'monthly_installment',
        'start_date',
        'first_due_date',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'product_price' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'processing_fee' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'financed_amount' => 'decimal:2',
            'total_interest' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'monthly_installment' => 'decimal:2',
            'start_date' => 'date',
            'first_due_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesman_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AgreementItem::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    public function outstandingBalance(): string
    {
        return $this->schedules()
            ->selectRaw('COALESCE(SUM(total_due - amount_paid), 0) as balance')
            ->value('balance') ?? '0.00';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
