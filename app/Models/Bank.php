<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank or wallet a shop receives and makes payments through. Replaces the
 * old fixed Bank/EasyPaisa/JazzCash/Other list — Cash is not stored here,
 * it stays a built-in option since it isn't a transfer destination.
 */
class Bank extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'name',
        'account_title',
        'account_number',
        'branch',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Account details shown under the name, where there are any. */
    public function detailLine(): ?string
    {
        return collect([$this->account_title, $this->account_number, $this->branch])
            ->filter()
            ->implode(' · ') ?: null;
    }
}
