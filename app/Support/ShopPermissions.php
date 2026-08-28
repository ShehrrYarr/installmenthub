<?php

namespace App\Support;

/**
 * The permissions a Shop Admin can toggle per-Manager/per-Salesman from
 * Settings → Staff. Unlike a user's role (which comes from a globally-shared
 * Spatie role), these are granted directly on the User model, so turning one
 * on or off for a staff member never affects anyone else's account.
 */
class ShopPermissions
{
    public const MANAGE_PRODUCTS = 'manage-products';

    public const MANAGE_VENDORS = 'manage-vendors';

    public const MANAGE_PURCHASE_ORDERS = 'manage-purchase-orders';

    public const APPROVE_AGREEMENTS = 'approve-agreements';

    /** @var list<string> */
    public const TOGGLEABLE = [
        self::MANAGE_PRODUCTS,
        self::MANAGE_VENDORS,
        self::MANAGE_PURCHASE_ORDERS,
        self::APPROVE_AGREEMENTS,
    ];

    public static function definitions(): array
    {
        return [
            self::MANAGE_PRODUCTS => [
                'label' => 'Products',
                'description' => 'Create, edit, and view the product catalog.',
            ],
            self::MANAGE_VENDORS => [
                'label' => 'Vendors',
                'description' => 'Create, edit, and view vendors.',
            ],
            self::MANAGE_PURCHASE_ORDERS => [
                'label' => 'Purchase Orders',
                'description' => 'Create purchase orders and receive stock.',
            ],
            self::APPROVE_AGREEMENTS => [
                'label' => 'Approve Agreements',
                'description' => 'Approve, cancel, or mark agreements as defaulted.',
            ],
        ];
    }

    /**
     * The permission set a role starts with when a Shop Admin adds a new
     * staff member — the Shop Admin can still uncheck any of these before
     * saving, or toggle them later from Settings → Staff.
     *
     * @return list<string>
     */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'Manager' => self::TOGGLEABLE,
            default => [],
        };
    }
}
