<?php

namespace App\Support;

use App\Models\CustomerLedgerEntry;
use App\Models\ProductSerial;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes goods back from a counter sale — whole or part — puts them back in
 * stock, and settles the money.
 *
 * What a returned unit is worth is what the customer actually paid for it:
 * its line total, less its share of any bill discount. What happens to that
 * money depends on where the sale stands:
 *
 *  - Still owing a balance? The return first reduces what they owe.
 *  - Paid more than they now owe? The excess is the refund — handed back as
 *    cash or bank, or (for a registered customer) left as credit on their
 *    ledger.
 *
 * Errors are keyed `items`, `items.{sale_item_id}`, `refund.mode`,
 * `refund.bank_id` for the sale page's return form.
 */
final class PosReturns
{
    public function __construct(
        private readonly Shop $shop,
        private readonly User $user,
    ) {}

    /**
     * What returning these quantities is worth and how much of it is money
     * back. Pure arithmetic — nothing is written.
     *
     * @param  Collection<int, SaleItem>  $items  the sale's items, keyed by id
     * @param  array<int, int|string>  $quantities  sale_item_id => units to return
     * @return array{value: string, overpaid: string, complete: bool, lines: array<int, array{item: SaleItem, quantity: int, amount: string}>}
     */
    public function quote(Sale $sale, Collection $items, array $quantities): array
    {
        $afterLines = bcsub((string) $sale->subtotal, (string) $sale->line_discount_total, 2);
        // What each rupee of line total is really worth once the bill discount is spread over it.
        $afterBill = bcsub($afterLines, (string) $sale->bill_discount_amount, 2);

        $lines = [];
        $value = '0.00';

        foreach ($quantities as $itemId => $quantity) {
            $quantity = (int) $quantity;

            if ($quantity < 1) {
                continue;
            }

            /** @var SaleItem|null $item */
            $item = $items->get((int) $itemId);

            if (! $item) {
                throw $this->fail('items', 'One of those items is not on this sale.');
            }

            if ($quantity > $item->returnableQuantity()) {
                throw $this->fail("items.{$itemId}", $item->returnableQuantity() > 0
                    ? "Only {$item->returnableQuantity()} of {$item->product_name} can still be returned."
                    : "{$item->product_name} has already been returned.");
            }

            $amount = bcadd(
                bcdiv(
                    bcmul(bcmul((string) $item->line_total, (string) $quantity, 6), $afterBill, 6),
                    bcmul((string) $item->quantity, $afterLines, 6),
                    6
                ),
                '0.005',
                2
            );

            $lines[(int) $itemId] = ['item' => $item, 'quantity' => $quantity, 'amount' => $amount];
            $value = bcadd($value, $amount, 2);
        }

        // Taking back the very last units returns exactly what is left of the
        // sale — rounding on the way must never leave a paisa behind or hand
        // back more than was charged.
        $remaining = bcsub((string) $sale->total, (string) $sale->returned_total, 2);
        $complete = $items->every(fn (SaleItem $item) => $item->returnableQuantity() - ($lines[$item->id]['quantity'] ?? 0) === 0);

        if (($complete || bccomp($value, $remaining, 2) > 0) && $lines !== []) {
            $difference = bcsub($remaining, $value, 2);
            $lastKey = array_key_last($lines);
            $lines[$lastKey]['amount'] = bcadd($lines[$lastKey]['amount'], $difference, 2);
            $value = $remaining;
        }

        $netTotal = bcsub((string) $sale->total, bcadd((string) $sale->returned_total, $value, 2), 2);
        $excess = bcsub($sale->netPaid(), $netTotal, 2);

        return [
            'value' => $value,
            'overpaid' => bccomp($excess, '0', 2) > 0 ? $excess : '0.00',
            'complete' => $complete && $lines !== [],
            'lines' => $lines,
        ];
    }

    /**
     * @param  array<int, int|string>  $quantities  sale_item_id => units to return
     * @param  array{mode?: ?string, bank_id?: int|string|null, reference?: ?string, proof?: mixed}  $refund
     */
    public function process(Sale $sale, array $quantities, array $refund = [], ?string $notes = null): SaleReturn
    {
        // Livewire actions skip the route group's check — see PosCheckout.
        abort_unless($this->shop->posEnabled(), 404);
        abort_unless($sale->shop_id === $this->shop->id, 404);

        $quantities = array_filter($quantities, fn ($quantity) => (int) $quantity > 0);

        if ($quantities === []) {
            throw $this->fail('items', 'Choose at least one item to return.');
        }

        return DB::transaction(function () use ($sale, $quantities, $refund, $notes) {
            // Shop first (it serialises return numbering), then the sale.
            Shop::whereKey($this->shop->id)->lockForUpdate()->first();

            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $items = SaleItem::where('sale_id', $locked->id)->lockForUpdate()->get()->keyBy('id');

            $quote = $this->quote($locked, $items, $quantities);

            if ($quote['lines'] === []) {
                throw $this->fail('items', 'Choose at least one item to return.');
            }

            [$mode, $bankId, $refundAmount, $creditAmount] = $this->settleMoney($locked, $quote['overpaid'], $refund);

            $sequence = (int) SaleReturn::withoutGlobalScopes()->where('shop_id', $this->shop->id)->max('sequence') + 1;

            $return = SaleReturn::create([
                'shop_id' => $this->shop->id,
                'sale_id' => $locked->id,
                'sequence' => $sequence,
                'return_number' => 'R-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'returned_by' => $this->user->id,
                'total_value' => $quote['value'],
                'refund_amount' => $refundAmount,
                'credit_amount' => $creditAmount,
                'payment_mode' => $mode,
                'bank_id' => $bankId,
                'reference_number' => $mode === PaymentMethod::BANK ? $this->clean($refund['reference'] ?? null) : null,
                'notes' => $this->clean($notes),
                'returned_at' => now(),
            ]);

            foreach ($quote['lines'] as $line) {
                /** @var SaleItem $item */
                $item = $line['item'];

                SaleReturnItem::create([
                    'shop_id' => $this->shop->id,
                    'sale_return_id' => $return->id,
                    'sale_item_id' => $item->id,
                    'quantity' => $line['quantity'],
                    'amount' => $line['amount'],
                ]);

                $item->increment('returned_quantity', $line['quantity']);

                // Back on the shelf. Only a unit still marked sold is put back —
                // one that has since been written off keeps its status.
                if ($item->product_serial_id) {
                    ProductSerial::whereKey($item->product_serial_id)->where('status', 'sold')
                        ->update(['status' => 'in_stock', 'sold_at' => null]);
                }
            }

            $locked->update([
                'returned_total' => bcadd((string) $locked->returned_total, $quote['value'], 2),
                'refunded_total' => bcadd((string) $locked->refunded_total, $refundAmount, 2),
                'status' => $quote['complete'] ? Sale::STATUS_RETURNED : $locked->status,
            ]);

            if ($mode === PaymentMethod::BANK && ($refund['proof'] ?? null) instanceof UploadedFile) {
                $return->addMedia($refund['proof']->getRealPath())
                    ->usingFileName($refund['proof']->getClientOriginalName())
                    ->toMediaCollection(ReceiptProof::COLLECTION);
            }

            if ($locked->customer_id) {
                $this->postToLedger($locked, $return, $refundAmount);
            }

            return $return->load('items');
        });
    }

    /**
     * Decides where the excess goes. With nothing paid beyond what is now
     * owed there is no refund and no choice to make.
     *
     * @param  array<string, mixed>  $refund
     * @return array{0: ?string, 1: ?int, 2: string, 3: string} mode, bank id, paid out, left on ledger
     */
    private function settleMoney(Sale $sale, string $overpaid, array $refund): array
    {
        if (bccomp($overpaid, '0', 2) <= 0) {
            return [null, null, '0.00', '0.00'];
        }

        $mode = $refund['mode'] ?? null;
        $label = PosCalculator::money($overpaid);

        $allowed = [PaymentMethod::CASH, PaymentMethod::BANK, SaleReturn::MODE_CREDIT];

        if (! in_array($mode, $allowed, true)) {
            throw $this->fail('refund.mode', "Choose how the {$label} goes back to the customer.");
        }

        if ($mode === SaleReturn::MODE_CREDIT) {
            if (! $sale->customer_id) {
                throw $this->fail('refund.mode', 'Only a registered customer can be left a credit on their ledger — refund this in cash or bank.');
            }

            return [$mode, null, '0.00', $overpaid];
        }

        if ($mode === PaymentMethod::BANK) {
            $bankIds = PaymentMethod::banks()->pluck('id')->all();
            $bankId = (int) ($refund['bank_id'] ?? 0);

            if (! in_array($bankId, $bankIds, true)) {
                throw $this->fail('refund.bank_id', 'Please select a bank.');
            }

            return [$mode, $bankId, $overpaid, '0.00'];
        }

        return [$mode, null, $overpaid, '0.00'];
    }

    /**
     * The goods coming back reduce what the customer owes (a credit); any
     * cash or bank refund is money leaving, so it is a debit that cancels the
     * credit it was paid against. A refund left on the ledger has no second
     * entry — the credit simply stands.
     */
    private function postToLedger(Sale $sale, SaleReturn $return, string $refundAmount): void
    {
        $date = now()->toDateString();

        CustomerLedgerEntry::create([
            'shop_id' => $this->shop->id,
            'customer_id' => $sale->customer_id,
            'agreement_id' => null,
            'type' => 'credit',
            'amount' => $return->total_value,
            'running_balance' => '0.00',
            'reference_type' => SaleReturn::class,
            'reference_id' => $return->id,
            'description' => "Return — {$sale->sale_number} ({$return->return_number})",
            'entry_date' => $date,
            'created_by' => $this->user->id,
        ]);

        if (bccomp($refundAmount, '0', 2) > 0) {
            CustomerLedgerEntry::create([
                'shop_id' => $this->shop->id,
                'customer_id' => $sale->customer_id,
                'agreement_id' => null,
                'type' => 'debit',
                'amount' => $refundAmount,
                'payment_mode' => $return->payment_mode,
                'bank_id' => $return->bank_id,
                'running_balance' => '0.00',
                'reference_type' => SaleReturn::class,
                'reference_id' => $return->id,
                'description' => "Refund paid — {$sale->sale_number} ({$return->return_number})",
                'entry_date' => $date,
                'created_by' => $this->user->id,
            ]);
        }

        CustomerLedgerEntry::recalculateFor($sale->customer_id);
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
