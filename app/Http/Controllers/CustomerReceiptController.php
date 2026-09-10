<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The same printable receipt staff use, reachable from the customer portal.
 * Separate from ThermalReceiptController because that one runs on the staff
 * guard — here the payment must belong to the signed-in customer, which is
 * the only thing standing between one customer and another's receipts.
 */
class CustomerReceiptController extends Controller
{
    /** $shop comes from the {shop} prefix and is bound before {payment} — both must be declared, in order. */
    public function __invoke(Shop $shop, Payment $payment): View
    {
        abort_unless($payment->customer_id === Auth::guard('customer')->id(), 403);

        $payment->load(['agreement.shop', 'agreement.schedules', 'customer', 'receiver', 'bank']);

        return view('receipts.thermal', ['payment' => $payment]);
    }
}
