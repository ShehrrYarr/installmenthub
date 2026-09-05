<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CustomerLedgerEntry extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'customer_id',
        'agreement_id',
        'type',
        'amount',
        'payment_mode',
        'running_balance',
        'reference_type',
        'reference_id',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
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
        return $this->hasMany(CustomerLedgerEntryRevision::class)->latest('id');
    }

    /** Manually-added entries (Cash In/Cash Out) have no source document — everything else is system-generated and must never be edited. */
    public function isManual(): bool
    {
        return $this->reference_type === null;
    }

    /** This entry's own payment_mode (manual entries), or the mode of the Payment it's linked to (e.g. a down payment or installment collection). */
    public function paymentModeLabel(): ?string
    {
        $mode = $this->payment_mode ?? ($this->reference_type === Payment::class ? $this->reference?->payment_mode : null);

        return $mode ? ucfirst($mode) : null;
    }

    /**
     * Recomputes running_balance for every one of this customer's ledger
     * entries in true chronological order (entry_date, then id as a
     * tie-breaker) — the only way to stay correct once an entry can be
     * added, edited, or backdated out of insertion order.
     */
    public static function recalculateFor(int $customerId): void
    {
        $running = '0.00';

        static::where('customer_id', $customerId)
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
}
