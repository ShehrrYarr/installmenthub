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
 * A direct counter sale — paid for now, no installment agreement. Distinct
 * from Agreement: stock and the customer ledger are shared, nothing else is.
 */
class Sale extends Model
{
    use BelongsToShop, HasFactory;

    public const STATUS_COMPLETED = 'completed';

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

    public function balanceDue(): string
    {
        return bcsub((string) $this->total, (string) $this->amount_paid, 2);
    }

    /** How the sale was paid, e.g. "Cash" or "Cash + Meezan Bank". */
    public function paymentSummary(): string
    {
        return $this->payments
            ->map(fn (SalePayment $payment) => PaymentMethod::label($payment->payment_mode, $payment->bank) ?? '—')
            ->unique()
            ->implode(' + ');
    }

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
        $lines[] = '';
        $lines[] = 'Thank you for shopping with us.';

        return implode("\n", $lines);
    }

    /**
     * Lines as a customer should read them. A non-serialized product drawn
     * from two purchase batches is stored as two rows (so each batch's stock
     * and cost stay exact), but on paper it is one line of the same product
     * at the same price.
     *
     * @return Collection<int, object{name: string, serial: ?string, quantity: int, unit_price: string, discount: string, line_total: string}>
     */
    public function displayLines(): Collection
    {
        return $this->items
            ->groupBy(fn (SaleItem $item) => $item->product_id.'|'.$item->unit_price.'|'.($item->serial_number ?? ''))
            ->map(function (Collection $group) {
                $first = $group->first();

                return (object) [
                    'name' => $first->product_name,
                    'serial' => $first->serial_number,
                    'quantity' => (int) $group->sum('quantity'),
                    'unit_price' => (string) $first->unit_price,
                    'discount' => $group->reduce(fn (string $carry, SaleItem $item) => bcadd($carry, (string) $item->discount_amount, 2), '0.00'),
                    'line_total' => $group->reduce(fn (string $carry, SaleItem $item) => bcadd($carry, (string) $item->line_total, 2), '0.00'),
                ];
            })
            ->values();
    }
}
