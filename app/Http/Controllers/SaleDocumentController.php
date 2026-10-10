<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Printable documents for a counter sale: the 80mm thermal receipt handed
 * over at the till, and the A4 invoice.
 *
 * Any staff member of the shop may print either — whoever can ring a sale
 * through can hand the customer their paper. $shop comes first because the
 * route prefix binds it before {sale} (see TenantDocumentController).
 */
class SaleDocumentController extends Controller
{
    public function receipt(Shop $shop, Sale $sale): View
    {
        return view('receipts.sale', $this->data($sale));
    }

    public function invoice(Shop $shop, Sale $sale): View
    {
        return view('invoices.sale', $this->data($sale));
    }

    /** The receipt for one payment taken against the sale — the original or a later repayment. */
    public function paymentReceipt(Shop $shop, Sale $sale, SalePayment $payment): View
    {
        abort_unless($payment->sale_id === $sale->id, 404);

        return view('receipts.sale-payment', $this->data($sale) + ['payment' => $payment->load(['bank', 'receiver'])]);
    }

    /** @return array{sale: Sale, shop: Shop} */
    private function data(Sale $sale): array
    {
        $user = Auth::user();

        $authorized = $user->hasRole('Super Admin')
            || $sale->shop_id === (Tenant::id() ?? $user->shop_id);

        abort_unless($authorized, 403);

        $sale->load(['shop', 'customer', 'seller', 'items', 'payments.bank', 'returns']);

        return ['sale' => $sale, 'shop' => $sale->shop];
    }
}
