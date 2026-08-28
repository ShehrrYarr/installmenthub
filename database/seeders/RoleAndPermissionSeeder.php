<?php

namespace Database\Seeders;

use App\Support\ShopPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'manage-shops',
            'manage-vendors',
            'manage-products',
            'manage-purchase-orders',
            'manage-customers',
            'manage-agreements',
            'collect-payments',
            'view-reports',
            'approve-agreements',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $shopAdmin = Role::firstOrCreate(['name' => 'Shop Admin', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $salesman = Role::firstOrCreate(['name' => 'Salesman', 'guard_name' => 'web']);

        $superAdmin->syncPermissions(['manage-shops']);

        // Shop Admin keeps every permission on the role itself — Admins always
        // have full access and aren't subject to the per-staff toggles below.
        $shopAdmin->syncPermissions([
            'manage-vendors', 'manage-products', 'manage-purchase-orders', 'approve-agreements',
            'manage-customers', 'manage-agreements', 'collect-payments', 'view-reports',
        ]);

        // ShopPermissions::TOGGLEABLE ('manage-vendors', 'manage-products',
        // 'manage-purchase-orders', 'approve-agreements') are deliberately NOT
        // synced onto the Manager/Salesman roles here. Spatie roles are global
        // (not per-shop), so granting them at the role level would hand every
        // Manager/Salesman across every shop the same access — a Shop Admin
        // couldn't turn Products access off for just one of their own Managers
        // without also affecting Managers at other shops. Instead these four
        // are granted per-user (see backfill below), which Settings → Staff
        // then lets each Shop Admin toggle independently for their own team.
        $manager->syncPermissions([
            'manage-customers', 'manage-agreements', 'collect-payments', 'view-reports',
        ]);

        $salesman->syncPermissions([
            'manage-customers', 'manage-agreements', 'collect-payments',
        ]);

        // Backfill: give existing Managers the direct permissions that used to
        // come from the role, so behavior is unchanged for accounts that
        // existed before per-staff toggles (existing Salesmen get none, also
        // unchanged). Idempotent — givePermissionTo() no-ops if already held.
        $manager->users()->get()->each(
            fn ($user) => $user->givePermissionTo(ShopPermissions::TOGGLEABLE)
        );
    }
}
