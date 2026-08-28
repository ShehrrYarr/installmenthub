<?php

namespace App\Models;

use App\Traits\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guarantor extends Model
{
    use BelongsToShop, HasFactory;

    protected $fillable = [
        'shop_id',
        'agreement_id',
        'name',
        'cnic_number',
        'mobile_number',
        'relation',
        'work_address',
    ];

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }
}
