<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a register's cart into a stored sale — in one transaction, so stock,
 * payments and the customer ledger either all move or none do.
 *
 * Everything the register shows is advisory: prices come from the catalog,
 * stock is re-checked, and the payments are re-validated here, so a tampered
 * browser state can't produce a sale the shop didn't intend. Payments may
 * fall short of the total only for a registered customer, with a due date.
 *
 * Failures are ValidationExceptions keyed to the register's own fields
 * (`cart`, `cart.{n}.…`, `payments`, `payments.{n}.…`), which Livewire turns
 * straight into inline errors.
 */
final class PosCheckout
{
    public function __construct(
        private readonly Shop $shop,
        private readonly User $user,
    ) {}

    /**
     * @param  array<int, array{product_id: int|string|null, product_serial_id?: int|string|null, quantity?: int|string, discount_type?: string, discount_value?: string}>  $lines
     * @param  array<int, array{method?: string, bank_id?: int|string|null, amount?: string, reference?: ?string, tendered?: ?string, proof?: ?UploadedFile}>  $payments
     * @param  array{customer_id?: int|string|null, walk_in_name?: ?string, walk_in_phone?: ?string, bill_discount_type?: string, bill_discount_value?: string, due_date?: ?string, notes?: ?string}  $details
     */
    public function complete(array $lines, array $payments, array $details = []): Sale
    {
        // The route group checks this on page load; a Livewire action never
        // passes back through it, so the switch is enforced here as well.
        abort_unless($this->shop->posEnabled(), 404);

        $lines = array_values($lines);
        $payments = array_values($payments);

        if ($lines === []) {
            throw $this->fail('cart', 'Add at least one product to the sale.');
        }

        $customer = null;

        if (! empty($details['customer_id'])) {
            // Tenant-scoped lookup — another shop's customer id simply isn't found.
            $customer = Customer::where('is_active', true)->find($details['customer_id']);

            if (! $customer) {
                throw $this->fail('customer_id', 'Select a valid customer.');
            }
        }

        $billType = $details['bill_discount_type'] ?? PosCalculator::FIXED;
        $billValue = (string) ($details['bill_discount_value'] ?? '0');

        // Keyed to the register's own field name so the error lands under it.
        $this->assertDiscount('billDiscountValue', $billType, $billValue);

        return DB::transaction(function () use ($lines, $payments, $details, $customer, $billType, $billValue) {
            // Taken first, and held until commit: it serialises this shop's
            // checkouts, so the stock check below and the sale number can't
            // race a sale being rung up on another till at the same moment.
            Shop::whereKey($this->shop->id)->lockForUpdate()->first();

            $resolved = $this->resolveLines($lines);

            $totals = PosCalculator::totals(
                collect($resolved)->map(fn (array $line) => [
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'discount_type' => $line['discount_type'],
                    'discount_value' => $line['discount_value'],
                ])->all(),
                $billType,
                $billValue,
            );

            if (bccomp($totals['total'], '0', 2) <= 0) {
                throw $this->fail('cart', 'The sale total must be more than zero.');
            }

            $lines = new PosPaymentLines($this->shop, $this->user);

            [$paid, $dueDate] = $this->settle($lines->validate($payments), $totals['total'], $customer, $details);

            $sequence = (int) Sale::withoutGlobalScopes()->where('shop_id', $this->shop->id)->max('sequence') + 1;

            $sale = Sale::create([
                'shop_id' => $this->shop->id,
                'sequence' => $sequence,
                'sale_number' => 'S-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'customer_id' => $customer?->id,
                // A walk-in's name/phone only matter when there's no customer record.
                'walk_in_name' => $customer ? null : $this->clean($details['walk_in_name'] ?? null),
                'walk_in_phone' => $customer ? null : $this->clean($details['walk_in_phone'] ?? null),
                'sold_by' => $this->user->id,
                'status' => Sale::STATUS_COMPLETED,
                'subtotal' => $totals['subtotal'],
                'line_discount_total' => $totals['line_discount_total'],
                'bill_discount_type' => $billType,
                'bill_discount_value' => PosCalculator::number($billValue),
                'bill_discount_amount' => $totals['bill_discount_amount'],
                'total' => $totals['total'],
                'amount_paid' => $paid,
                'due_date' => $dueDate,
                'notes' => $this->clean($details['notes'] ?? null),
                'sold_at' => now(),
            ]);

            foreach ($resolved as $index => $line) {
                $this->storeLine($sale, $line, $totals['lines'][$index]['discount']);
            }

            $storedPayments = $lines->store($sale, $payments, $customer);

            if ($customer) {
                $this->postToLedger($sale, $customer, $storedPayments, $lines);
            }

            return $sale->load(['items', 'payments', 'customer']);
        });
    }

    /**
     * Checks every line against the catalog and stock and works out what each
     * one will be sold at.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveLines(array $lines): array
    {
        $products = Product::whereIn('id', collect($lines)->pluck('product_id')->filter()->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $resolved = [];
        $seenSerials = [];
        $requested = [];

        foreach ($lines as $index => $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if (! $product) {
                throw $this->fail("cart.{$index}.product_id", 'That product is no longer available.');
            }

            $quantity = (int) ($line['quantity'] ?? 1);

            if ($quantity < 1) {
                throw $this->fail("cart.{$index}.quantity", 'Quantity must be at least 1.');
            }

            $discountType = $line['discount_type'] ?? PosCalculator::FIXED;
            $discountValue = (string) ($line['discount_value'] ?? '0');

            $this->assertDiscount("cart.{$index}.discount_value", $discountType, $discountValue);

            $serial = null;

            if ($product->is_serialized) {
                $serialId = (int) ($line['product_serial_id'] ?? 0);

                if (! $serialId) {
                    throw $this->fail("cart.{$index}.product_serial_id", "Pick the unit of {$product->name} being sold.");
                }

                if ($quantity !== 1) {
                    throw $this->fail("cart.{$index}.quantity", 'A serialized unit is sold one at a time.');
                }

                if (in_array($serialId, $seenSerials, true)) {
                    throw $this->fail("cart.{$index}.product_serial_id", 'That unit is on the sale twice.');
                }

                $serial = ProductSerial::with('purchaseOrderItem')
                    ->where('product_id', $product->id)
                    ->where('status', 'in_stock')
                    ->find($serialId);

                if (! $serial) {
                    throw $this->fail("cart.{$index}.product_serial_id", 'That unit is no longer in stock.');
                }

                $seenSerials[] = $serialId;
            } else {
                $requested[$product->id] = ($requested[$product->id] ?? 0) + $quantity;
            }

            $resolved[$index] = [
                'product' => $product,
                'serial' => $serial,
                'quantity' => $quantity,
                'unit_price' => (string) $product->cash_price,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
            ];
        }

        foreach ($requested as $productId => $wanted) {
            $product = $products->get($productId);
            $available = $product->availableQuantity();

            if ($wanted > $available) {
                throw $this->fail('cart', $available > 0
                    ? "Only {$available} of {$product->name} left in stock."
                    : "{$product->name} is out of stock.");
            }
        }

        return $resolved;
    }

    /**
     * Persists one cart line — one row for a serialized unit, or one row per
     * purchase batch drawn on for a non-serialized product (oldest first), so
     * each batch's remaining stock and its cost stay exact.
     *
     * @param  array<string, mixed>  $line
     */
    private function storeLine(Sale $sale, array $line, string $lineDiscount): void
    {
        /** @var Product $product */
        $product = $line['product'];

        $base = [
            'shop_id' => $this->shop->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'unit_price' => $line['unit_price'],
            'discount_type' => $line['discount_type'],
            'discount_value' => PosCalculator::number($line['discount_value']),
        ];

        if ($serial = $line['serial']) {
            // Conditional update, so a unit that sold on an agreement a moment
            // ago fails here instead of being sold twice.
            $claimed = ProductSerial::whereKey($serial->id)->where('status', 'in_stock')->update([
                'status' => 'sold',
                'sold_at' => now(),
            ]);

            if ($claimed !== 1) {
                throw $this->fail('cart', "{$product->name} ({$serial->serial_number}) was just sold — remove it and try again.");
            }

            SaleItem::create($base + [
                'product_serial_id' => $serial->id,
                'purchase_order_item_id' => null,
                'serial_number' => $serial->serial_number,
                'quantity' => 1,
                'discount_amount' => $lineDiscount,
                'line_total' => bcsub($line['unit_price'], $lineDiscount, 2),
                'cost_price' => (string) ($serial->purchaseOrderItem?->cost_price ?? $product->cost_price),
            ]);

            return;
        }

        $slices = $this->allocateBatches($product, $line['quantity']);
        $remainingDiscount = $lineDiscount;

        foreach ($slices as $position => $slice) {
            $isLast = $position === array_key_last($slices);
            $gross = bcmul($line['unit_price'], (string) $slice['quantity'], 2);

            // Proportional share of the line's discount; the last slice takes
            // whatever is left so the pieces always add back to the whole.
            $discount = $isLast
                ? $remainingDiscount
                : bcadd(bcdiv(bcmul($lineDiscount, (string) $slice['quantity'], 6), (string) $line['quantity'], 6), '0.005', 2);

            $remainingDiscount = bcsub($remainingDiscount, $discount, 2);

            SaleItem::create($base + [
                'product_serial_id' => null,
                'purchase_order_item_id' => $slice['batch'],
                'serial_number' => null,
                'quantity' => $slice['quantity'],
                'discount_amount' => $discount,
                'line_total' => bcsub($gross, $discount, 2),
                'cost_price' => $slice['cost'],
            ]);
        }
    }

    /**
     * Draws `$quantity` units from the product's received purchase batches,
     * oldest first. Whatever no batch accounts for (stock entered without a
     * purchase order) falls back to the product's own cost price.
     *
     * @return array<int, array{batch: ?int, quantity: int, cost: string}>
     */
    private function allocateBatches(Product $product, int $quantity): array
    {
        $batches = PurchaseOrderItem::where('product_id', $product->id)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', 'received'))
            ->withSum('heldAgreementItems as agreement_sold', 'quantity')
            ->withSum('saleItems as pos_sold', SaleItem::netQuantityExpression())
            ->orderBy('id')
            ->get();

        $slices = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            $left = $batch->quantity - (int) $batch->agreement_sold - (int) $batch->pos_sold;

            if ($left <= 0) {
                continue;
            }

            $take = min($left, $remaining);
            $slices[] = ['batch' => $batch->id, 'quantity' => $take, 'cost' => (string) $batch->cost_price];
            $remaining -= $take;

            if ($remaining === 0) {
                break;
            }
        }

        if ($remaining > 0) {
            $slices[] = ['batch' => null, 'quantity' => $remaining, 'cost' => (string) $product->cost_price];
        }

        return $slices;
    }

    /**
     * Works out what is paid now and what is left on credit.
     *
     * A balance is only ever left to a registered customer, with a date to
     * settle it by — otherwise nobody could be asked for the money later.
     * Paying more than the total is never intended (change is the till's
     * business, tracked through `tendered`).
     *
     * @param  array<string, mixed>  $details
     * @return array{0: string, 1: ?string} amount paid now, due date (null when settled)
     */
    private function settle(string $paid, string $total, ?Customer $customer, array $details): array
    {
        if (bccomp($paid, $total, 2) > 0) {
            throw $this->fail('payments', 'Payments add up to Rs. '.number_format((float) $paid, 0).', which is more than the total of Rs. '.number_format((float) $total, 0).'.');
        }

        if (bccomp($paid, $total, 2) === 0) {
            return [$paid, null];
        }

        $balance = bcsub($total, $paid, 2);

        if (! $customer) {
            throw $this->fail('payments', 'Rs. '.number_format((float) $balance, 0).' is still unpaid. Attach a registered customer to sell on credit, or take the full amount.');
        }

        $due = trim((string) ($details['due_date'] ?? ''));

        if ($due === '') {
            throw $this->fail('dueDate', 'Choose the date the balance is due.');
        }

        try {
            $dueDate = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $due)->startOfDay();
        } catch (\Throwable) {
            throw $this->fail('dueDate', 'Enter a valid due date.');
        }

        if ($dueDate->lt(today())) {
            throw $this->fail('dueDate', "The due date can't be in the past.");
        }

        return [$paid, $dueDate->toDateString()];
    }

    /**
     * The same shape agreements use: the obligation as one debit, each
     * payment as its own credit — so a fully paid sale nets to nothing, a
     * sale on credit leaves exactly its balance, and the customer's history
     * still shows what they bought and how they paid.
     *
     * @param  array<int, SalePayment>  $payments
     */
    private function postToLedger(Sale $sale, Customer $customer, array $payments, PosPaymentLines $lines): void
    {
        CustomerLedgerEntry::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $customer->id,
            'agreement_id' => null,
            'type' => 'debit',
            'amount' => $sale->total,
            'running_balance' => '0.00',
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'description' => "POS sale — {$sale->sale_number}",
            'entry_date' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $lines->postToLedger($sale, $customer, $payments);

        CustomerLedgerEntry::recalculateFor($customer->id);
    }

    /** A discount must be a plain non-negative number, and a percentage can't exceed 100. */
    private function assertDiscount(string $field, string $type, string $value): void
    {
        if (! in_array($type, PosCalculator::discountTypes(), true)) {
            throw $this->fail($field, 'Choose Rs. or % for the discount.');
        }

        if (trim($value) === '') {
            return;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', trim($value))) {
            throw $this->fail($field, 'Enter a valid discount.');
        }

        if ($type === PosCalculator::PERCENT && bccomp(trim($value), '100', 2) > 0) {
            throw $this->fail($field, 'A percentage discount can\'t be more than 100.');
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function fail(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
