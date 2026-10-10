<?php

namespace App\Models;

use App\Support\PaymentMethod;
use App\Support\PosCalculator;
use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A direct counter sale. Distinct from Agreement: stock and the customer
 * ledger are shared, nothing else is.
 *
 * It may be paid in full at the till, or — for a registered customer — in
 * part, with the balance settled later against this same sale. Goods can be
 * taken back afterwards, in whole or in part.
 *
 * Money, in one place:
 *   total          what was charged (after discounts)
 *   returned_total value of goods since taken back
 *   amount_paid    everything the customer has paid
 *   refunded_total the part of that paid back out in cash/bank
 *
 *   netTotal  = total − returned_total       what they now owe for what they kept
 *   netPaid   = amount_paid − refunded_total  what the shop is holding from them
 *   balance   = max(0, netTotal − netPaid)    still to collect
 */
class Sale extends Model
{
    use BelongsToShop, HasFactory;

    public const STATUS_COMPLETED = 'completed';

    /** Every unit has been taken back. */
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'shop_id',
        'sequence',
        'sale_number',
        'customer_id',
        'walk_in_name',
        'walk_in_phone',
        'sold_by',
        'status',
        'subtotal',
        'line_discount_total',
        'bill_discount_type',
        'bill_discount_value',
        'bill_discount_amount',
        'total',
        'amount_paid',
        'returned_total',
        'refunded_total',
        'due_date',
        'notes',
        'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'bill_discount_value' => 'decimal:2',
            'bill_discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'returned_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'due_date' => 'date',
            'sold_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        // A customer can be archived after buying — the sale must still read.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function isWalkIn(): bool
    {
        return $this->customer_id === null;
    }

    /** Who the receipt is addressed to — the customer record, a typed-in name, or plain "Walk-in". */
    public function customerName(): string
    {
        if ($this->customer) {
            return $this->customer->full_name;
        }

        return $this->walk_in_name ?: 'Walk-in customer';
    }

    public function customerPhone(): ?string
    {
        return $this->customer?->phone ?: $this->walk_in_phone;
    }

    // ── Money ───────────────────────────────────────────────────────────

    /** What is owed for the goods that were kept. */
    public function netTotal(): string
    {
        return bcsub((string) $this->total, (string) $this->returned_total, 2);
    }

    /** What the shop is holding from the customer: paid, less anything paid back out. */
    public function netPaid(): string
    {
        return bcsub((string) $this->amount_paid, (string) $this->refunded_total, 2);
    }

    /** Still to collect — never negative. */
    public function balanceDue(): string
    {
        $balance = bcsub($this->netTotal(), $this->netPaid(), 2);

        return bccomp($balance, '0', 2) > 0 ? $balance : '0.00';
    }

    /** More has been paid than is now owed — the customer is in credit. */
    public function overpaid(): string
    {
        $over = bcsub($this->netPaid(), $this->netTotal(), 2);

        return bccomp($over, '0', 2) > 0 ? $over : '0.00';
    }

    public function hasBalanceDue(): bool
    {
        return bccomp($this->balanceDue(), '0', 2) > 0;
    }

    public function isOverdue(): bool
    {
        return $this->hasBalanceDue()
            && $this->due_date !== null
            && $this->due_date->lt(today());
    }

    public function isFullyReturned(): bool
    {
        return $this->status === self::STATUS_RETURNED;
    }

    /** One word for the badge: returned, overdue, partial (balance owed) or paid. */
    public function paymentState(): string
    {
        return match (true) {
            $this->isFullyReturned() => 'returned',
            $this->isOverdue() => 'overdue',
            $this->hasBalanceDue() => 'partial',
            default => 'paid',
        };
    }

    /** How the sale was paid, e.g. "Cash" or "Cash + Meezan Bank". */
    public function paymentSummary(): string
    {
        return $this->payments
            ->map(fn (SalePayment $payment) => PaymentMethod::label($payment->payment_mode, $payment->bank) ?? '—')
            ->unique()
            ->implode(' + ') ?: 'On credit';
    }

    // ── Lines ───────────────────────────────────────────────────────────

    /**
     * Lines as a customer should read them. A non-serialized product drawn
     * from two purchase batches is stored as two rows (so each batch's stock
     * and cost stay exact), but on paper it is one line of the same product
     * at the same price.
     *
     * @return Collection<int, object{name: string, serial: ?string, quantity: int, returned: int, unit_price: string, discount: string, line_total: string}>
     */
    public function displayLines(): Collection
    {
        return $this->groupedItems()
            ->map(function (Collection $group) {
                $first = $group->first();

                return (object) [
                    'name' => $first->product_name,
                    'serial' => $first->serial_number,
                    'quantity' => (int) $group->sum('quantity'),
                    'returned' => (int) $group->sum('returned_quantity'),
                    'unit_price' => (string) $first->unit_price,
                    'discount' => $group->reduce(fn (string $carry, SaleItem $item) => bcadd($carry, (string) $item->discount_amount, 2), '0.00'),
                    'line_total' => $group->reduce(fn (string $carry, SaleItem $item) => bcadd($carry, (string) $item->line_total, 2), '0.00'),
                ];
            })
            ->values();
    }

    /**
     * The same grouping as displayLines(), keeping the stored rows — what the
     * return form works from. `returnable` is how many units can still come back.
     *
     * @return Collection<int, object{name: string, serial: ?string, unit_price: string, sold: int, returned: int, returnable: int, rows: Collection<int, SaleItem>}>
     */
    public function returnableGroups(): Collection
    {
        return $this->groupedItems()
            ->map(function (Collection $group) {
                $first = $group->first();

                return (object) [
                    'name' => $first->product_name,
                    'serial' => $first->serial_number,
                    'unit_price' => (string) $first->unit_price,
                    'sold' => (int) $group->sum('quantity'),
                    'returned' => (int) $group->sum('returned_quantity'),
                    'returnable' => (int) $group->sum(fn (SaleItem $item) => $item->returnableQuantity()),
                    'rows' => $group->values(),
                ];
            })
            ->values();
    }

    /** @return Collection<string, Collection<int, SaleItem>> */
    private function groupedItems(): Collection
    {
        return $this->items->groupBy(
            fn (SaleItem $item) => $item->product_id.'|'.$item->unit_price.'|'.($item->serial_number ?? '')
        );
    }

    // ── WhatsApp ────────────────────────────────────────────────────────

    /** The receipt as a WhatsApp message — addressed to the customer's number when there is one, else the share picker. */
    public function whatsappUrl(): string
    {
        $digits = preg_replace('/\D/', '', (string) $this->customerPhone());
        $number = $digits === '' ? '' : (str_starts_with($digits, '0') ? '92'.substr($digits, 1) : $digits);

        return 'https://wa.me/'.$number.'?text='.rawurlencode($this->whatsappText());
    }

    public function whatsappText(): string
    {
        $lines = [
            $this->shop?->name ?? config('app.name'),
            "Receipt {$this->sale_number}",
            $this->sold_at->format('d M Y, h:i A'),
            '',
        ];

        foreach ($this->displayLines() as $line) {
            $lines[] = "{$line->name} x{$line->quantity} — ".PosCalculator::money($line->line_total);
        }

        $lines[] = '';
        $lines[] = 'Total: '.PosCalculator::money((string) $this->total);
        $lines[] = 'Paid: '.PosCalculator::money((string) $this->amount_paid).' ('.$this->paymentSummary().')';

        if ($this->hasBalanceDue()) {
            $lines[] = 'Balance due: '.PosCalculator::money($this->balanceDue())
                .($this->due_date ? ' by '.$this->due_date->format('d M Y') : '');
        }

        $lines[] = '';
        $lines[] = 'Thank you for shopping with us.';

        return implode("\n", $lines);
    }
}
