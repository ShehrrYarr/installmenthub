<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The counter-sale receipts staff print, reachable from the customer portal.
 * Separate from SaleDocumentController because that one runs on the staff
 * guard — here the sale must belong to the signed-in customer, which is the
 * only thing standing between one customer and another's purchases.
 */
class CustomerSaleReceiptController extends Controller
{
    /** $shop comes from the {shop} prefix and is bound before {sale} — both must be declared, in order. */
    public function sale(Shop $shop, Sale $sale): View
    {
        $this->authorizeFor($sale);

        return view('receipts.sale', ['sale' => $this->loaded($sale), 'shop' => $sale->shop, 'portal' => true]);
    }

    public function payment(Shop $shop, Sale $sale, SalePayment $payment): View
    {
        $this->authorizeFor($sale);
        abort_unless($payment->sale_id === $sale->id, 404);

        return view('receipts.sale-payment', [
            'sale' => $this->loaded($sale),
            'shop' => $sale->shop,
            'payment' => $payment->load(['bank', 'receiver']),
            'portal' => true,
        ]);
    }

    private function authorizeFor(Sale $sale): void
    {
        abort_unless($sale->customer_id !== null && $sale->customer_id === Auth::guard('customer')->id(), 403);
    }

    private function loaded(Sale $sale): Sale
    {
        return $sale->load(['shop', 'customer', 'seller', 'items', 'payments.bank', 'returns']);
    }
}
