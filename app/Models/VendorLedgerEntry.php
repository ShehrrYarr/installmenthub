<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use App\Traits\HasReceiptProof;
use App\Traits\ResolvesLedgerReceiptProof;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VendorLedgerEntry extends Model implements HasMedia
{
    use BelongsToShop, HasFactory, HasReceiptProof, ResolvesLedgerReceiptProof;

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'type',
        'amount',
        'payment_mode',
        'bank_id',
        'running_balance',
        'reference_type',
        'reference_id',
        'purchase_order_id',
        'manual_direction',
        'description',
        'entry_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'entry_date' => 'date',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VendorLedgerEntryRevision::class)->latest('id');
    }

    /** Manually-added entries (Cash In/Cash Out) have no source document — everything else is auto-generated at PO receipt and must never be edited. */
    public function isManual(): bool
    {
        return $this->reference_type === null;
    }

    public function paymentModeLabel(): ?string
    {
        return \App\Support\PaymentMethod::label($this->payment_mode, $this->bank);
    }

    /**
     * Recomputes running_balance for every one of this vendor's ledger
     * entries in true chronological order (entry_date, then id as a
     * tie-breaker) — the only way to stay correct once a manual entry can be
     * added, edited, or backdated out of insertion order relative to the
     * auto-generated purchase-order entries.
     */
    public static function recalculateFor(int $vendorId): void
    {
        $running = '0.00';

        static::where('vendor_id', $vendorId)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->each(function (self $entry) use (&$running) {
                $running = $entry->type === 'debit'
                    ? bcadd($running, (string) $entry->amount, 2)
                    : bcsub($running, (string) $entry->amount, 2);

                if (bccomp($running, (string) $entry->running_balance, 2) !== 0) {
                    $entry->update(['running_balance' => $running]);
                }
            });
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }
}
