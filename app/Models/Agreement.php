<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Agreement extends Model
{
    use BelongsToShop, HasFactory, SoftDeletes;

    public const STATUS_CANCELLED = 'cancelled';

    /** Statuses an agreement can still be cancelled from - once approved it is a live contract. */
    public const CANCELLABLE_STATUSES = ['draft', 'pending_approval'];

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
        'interest_rate_months',
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

    /**
     * Cancels an agreement that has not been approved yet and gives back the
     * stock it was holding: a serial/IMEI unit goes back to in_stock, and a
     * non-serialized product's quantity stops being counted against stock
     * (see AgreementItem::scopeHoldingStock()).
     *
     * Returns false, changing nothing, if the agreement is not in a
     * cancellable status - judged against the row as it is now, not as this
     * instance loaded it, so a stale page can't cancel an approved agreement.
     */
    public function cancel(): bool
    {
        return DB::transaction(function (): bool {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $current || ! in_array($current->status, self::CANCELLABLE_STATUSES, true)) {
                return false;
            }

            $current->update(['status' => self::STATUS_CANCELLED]);

            // Only a unit that is actually marked sold goes back - never one
            // that has since been written off or sold some other way.
            ProductSerial::whereIn(
                'id',
                AgreementItem::where('agreement_id', $current->id)->whereNotNull('product_serial_id')->select('product_serial_id')
            )
                ->where('status', 'sold')
                ->update(['status' => 'in_stock', 'sold_at' => null]);

            $this->setAttribute('status', self::STATUS_CANCELLED);
            $this->syncOriginalAttribute('status');

            return true;
        });
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The product name(s) on this agreement, for ledger descriptions and
     * anywhere else the agreement number alone is too cryptic. Every item
     * is listed — an agreement can carry more than one product.
     */
    public function productSummary(): ?string
    {
        // One line per unit, so two of the same phone are two items. Collapse
        // them to "Samsung Galaxy A15 x2" rather than repeating the name.
        $names = $this->items
            ->map(fn (AgreementItem $item) => $item->product?->name)
            ->filter()
            ->countBy()
            ->map(fn (int $count, string $name) => $count > 1 ? "{$name} x{$count}" : $name)
            ->values();

        return $names->isEmpty() ? null : $names->implode(', ');
    }

    /**
     * "AGR-1-0001 (Haier 1.5 Ton Inverter AC)", falling back to the bare
     * agreement number for an agreement with no named products.
     */
    public function ledgerLabel(): string
    {
        $products = $this->productSummary();

        return $products === null
            ? $this->agreement_number
            : "{$this->agreement_number} ({$products})";
    }
}
