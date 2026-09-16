<?php

namespace Tests\Feature\Security;

use App\Models\Agreement;
use App\Models\Customer;
use App\Models\InstallmentSchedule;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Regression tests written during the security review requested 2026-09-16.
 * Each test documents one finding from the review report. Tests marked
 * "confirms a real gap" are expected to FAIL on the current codebase — they
 * assert the *secure* behavior, which the app does not yet implement. They
 * should start passing once the corresponding fix lands.
 */
class SecurityFindingsTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true]);
    }

    /**
     * FINDING (Critical): customer_documents/vendor_documents media collections
     * have no ->useDisk('local') override, so they inherit the default
     * media-library disk, which is 'public' in this app's config. Any file
     * uploaded to a customer's KYC gallery is served from a predictable,
     * unauthenticated /storage/{id}/{filename} URL.
     *
     * This test currently FAILS: the uploaded document's disk is 'public'
     * and the file is fetchable with no authentication at all.
     */
    public function test_customer_kyc_document_is_not_publicly_readable(): void
    {
        $shop = $this->shop();
        $customer = Customer::create([
            'shop_id' => $shop->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'cnic_number' => '12345-1234567-1',
            'phone' => '03001234567',
            'is_active' => true,
        ]);

        $media = $customer->addMedia(UploadedFile::fake()->image('cnic-front.jpg'))
            ->preservingOriginal()
            ->toMediaCollection(Customer::MEDIA_COLLECTION);

        $this->assertSame(
            'local',
            $media->disk,
            'Customer KYC documents must be stored on the private ("local") disk, not the public one — '
            .'they contain CNIC/ID scans and income proof. Serving them from the public disk means anyone '
            .'who can guess/increment the numeric media id can download another shop\'s customers\' ID documents '
            .'with zero authentication.'
        );
    }

    public function test_vendor_document_is_not_publicly_readable(): void
    {
        $shop = $this->shop();
        $vendor = Vendor::create([
            'shop_id' => $shop->id,
            'name' => 'Test Vendor',
            'phone' => '03001234567',
            'is_active' => true,
        ]);

        $media = $vendor->addMedia(UploadedFile::fake()->image('trade-license.jpg'))
            ->preservingOriginal()
            ->toMediaCollection(Vendor::MEDIA_COLLECTION);

        $this->assertSame(
            'local',
            $media->disk,
            'Vendor documents (owner CNIC, trade license) must be stored on the private disk for the same '
            .'reason as customer KYC documents.'
        );
    }

    /**
     * FINDING (High): resources/views/livewire/superadmin/developer-tools.blade.php
     * has no mount()/action-level role check. It relies entirely on the
     * `role:Super Admin` ROUTE middleware, which Livewire does NOT re-apply
     * to the shared /livewire/update endpoint used by every subsequent
     * component action (confirmed against vendor/livewire/livewire's
     * PersistentMiddleware, which hardcodes a small allow-list that does not
     * include spatie/laravel-permission's `role` middleware).
     *
     * Volt::test() bypasses route middleware the same way a raw
     * /livewire/update call does, so this test reproduces the real attack
     * surface directly: a user who is authenticated but is NOT (or is no
     * longer) a Super Admin can still invoke run() and execute a real
     * artisan/shell command on the server.
     *
     * This test currently FAILS: run() does not abort for a non-Super-Admin.
     */
    public function test_non_super_admin_cannot_run_developer_tools_actions(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $shop = $this->shop();
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_active' => true]);
        $user->assignRole('Salesman');

        $this->actingAs($user);

        // mount() itself now rejects a non-Super-Admin, so the component
        // never successfully mounts for this user — asserting the mount
        // response is forbidden is the correct check here (see the
        // mid-session-revocation test below for the run()-level check).
        Volt::test('superadmin.developer-tools')->assertForbidden();
    }

    /**
     * Same finding, from the "role revoked mid-session" angle: even a user
     * who WAS a Super Admin when the page loaded must be re-checked on every
     * privileged action, not just at initial mount/route-resolution time.
     */
    public function test_developer_tools_rechecks_role_on_every_action(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create(['shop_id' => null, 'is_active' => true]);
        $user->assignRole('Super Admin');

        $this->actingAs($user);

        $component = Volt::test('superadmin.developer-tools')
            ->call('run', 'migrate-status')
            ->assertOk();

        // Role revoked out-of-band (e.g. another admin demotes this account),
        // but the session/browser tab is still alive with a valid snapshot.
        $user->removeRole('Super Admin');

        $component->call('run', 'migrate-status')->assertForbidden();
    }

    /**
     * FINDING (Medium): the overdue-penalty sweep (ApplyOverduePenalties)
     * updates InstallmentSchedule.total_due/penalty_amount, which is what
     * Agreement::outstandingBalance() (used on the Collection Desk) sums —
     * but it never writes a corresponding CustomerLedgerEntry debit. The
     * customer statement/ledger ("closing balance") therefore understates
     * what Collection Desk says is actually owed once a penalty is charged.
     *
     * This test currently FAILS: the two "outstanding balance" figures
     * diverge by exactly the penalty amount.
     */
    public function test_penalty_amount_is_reflected_in_customer_ledger_balance(): void
    {
        $shop = Shop::create([
            'name' => 'Test Shop', 'slug' => 'test-shop', 'is_active' => true,
            'penalty_type' => 'fixed', 'penalty_rate' => 600, 'grace_period_days' => 3,
        ]);
        $customer = Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'cnic_number' => '12345-1234567-1', 'phone' => '03001234567', 'is_active' => true,
        ]);
        $agreement = Agreement::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id,
            'agreement_number' => 'AGR-TEST-0001', 'status' => 'active',
            'product_price' => '10000.00', 'down_payment' => '0.00', 'processing_fee' => '0.00',
            'interest_rate' => '0.00', 'duration_months' => 1,
            'financed_amount' => '10000.00', 'total_interest' => '0.00', 'total_payable' => '10000.00',
            'monthly_installment' => '10000.00', 'start_date' => now()->subMonth(),
            'first_due_date' => now()->subDays(20),
        ]);
        InstallmentSchedule::create([
            'shop_id' => $shop->id, 'agreement_id' => $agreement->id,
            'installment_number' => 1, 'due_date' => now()->subDays(20),
            'opening_balance' => '10000.00', 'principal_component' => '10000.00',
            'interest_component' => '0.00', 'total_due' => '10000.00',
            'closing_balance' => '0.00', 'status' => 'pending', 'amount_paid' => '0.00',
        ]);

        \App\Models\CustomerLedgerEntry::create([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'agreement_id' => $agreement->id,
            'type' => 'debit', 'amount' => '10000.00', 'running_balance' => '10000.00',
            'reference_type' => Agreement::class, 'reference_id' => $agreement->id,
            'description' => 'New agreement', 'entry_date' => now()->subMonth()->toDateString(),
            'created_by' => null,
        ]);

        $this->artisan('installments:apply-overdue-penalties')->assertSuccessful();

        $scheduleOutstanding = (float) $agreement->fresh()->outstandingBalance();
        $ledgerClosingBalance = (float) $customer->ledgerEntries()->orderBy('entry_date')->orderBy('id')->get()->last()->running_balance;

        $this->assertSame(
            $scheduleOutstanding,
            $ledgerClosingBalance,
            "Collection Desk says Rs. {$scheduleOutstanding} is outstanding (includes the penalty), but the "
            ."customer's own ledger/statement closes at Rs. {$ledgerClosingBalance} — the penalty was never "
            .'posted as a ledger debit.'
        );
    }

    /**
     * Regression guard (currently PASSES): confirms cross-tenant agreement
     * access stays blocked. Kept here alongside the failing tests above so
     * this file also documents what the review verified as already secure.
     */
    public function test_staff_cannot_view_another_shops_agreement_by_id(): void
    {
        $shopA = Shop::create(['name' => 'Shop A', 'slug' => 'shop-a', 'is_active' => true]);
        $shopB = Shop::create(['name' => 'Shop B', 'slug' => 'shop-b', 'is_active' => true]);

        $customerB = Customer::create([
            'shop_id' => $shopB->id, 'first_name' => 'Bob', 'last_name' => 'Smith',
            'cnic_number' => '99999-9999999-9', 'phone' => '03009999999', 'is_active' => true,
        ]);
        $agreementB = Agreement::create([
            'shop_id' => $shopB->id, 'customer_id' => $customerB->id,
            'agreement_number' => 'AGR-B-0001', 'status' => 'active',
            'product_price' => '10000.00', 'down_payment' => '0.00', 'processing_fee' => '0.00',
            'interest_rate' => '0.00', 'duration_months' => 1,
            'financed_amount' => '10000.00', 'total_interest' => '0.00', 'total_payable' => '10000.00',
            'monthly_installment' => '10000.00', 'start_date' => now(), 'first_due_date' => now(),
        ]);

        $userA = User::factory()->create(['shop_id' => $shopA->id, 'is_active' => true]);
        $this->actingAs($userA);

        $response = $this->get("/s/{$shopA->slug}/agreements/{$agreementB->id}");

        $response->assertNotFound();
    }
}
