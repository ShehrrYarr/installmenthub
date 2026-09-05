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
}
