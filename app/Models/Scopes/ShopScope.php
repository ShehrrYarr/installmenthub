<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Confines every query on a tenant model to the current shop.
 *
 * The URL-resolved tenant (App\Support\Tenant, set by ResolveTenant for
 * /s/{shop}/* requests) takes priority — this is what lets a Super Admin
 * browse into one shop's URL and see only that shop's data. Outside of a
 * tenant request (console, queue, Super Admin's global /super-admin/*
 * routes) it falls back to the authenticated user's own shop_id, and Super
 * Admins / shop-less users bypass the filter entirely.
 */
class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($shopId = Tenant::id()) {
            $builder->where($model->qualifyColumn('shop_id'), $shopId);

            return;
        }

        $user = Auth::user();

        if (! $user || is_null($user->shop_id) || $user->hasRole('Super Admin')) {
            return;
        }

        $builder->where($model->qualifyColumn('shop_id'), $user->shop_id);
    }
}
