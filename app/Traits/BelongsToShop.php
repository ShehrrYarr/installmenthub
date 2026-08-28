<?php

namespace App\Traits;

use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Marks a model as tenant-scoped: every query is confined to the current
 * shop via ShopScope, and shop_id is stamped automatically on create.
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function (self $model) {
            if (! $model->shop_id) {
                // Prefer the URL-resolved tenant (e.g. Super Admin working inside
                // /s/{shop}/...) over the authenticated user's own shop_id.
                $model->shop_id = Tenant::id() ?? Auth::user()?->shop_id;
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
