<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Staff-guard counterpart to CustomerReceiptController. This route isn't
 * under /s/{shop}/... (it's reachable from any authenticated staff session,
 * see routes/web.php), so ShopScope's implicit filtering during route-model
 * binding is the only thing standing between one shop's staff and another
 * shop's receipt unless checked explicitly here too — mirrored on
 * ReceiptProofController::staffMayView().
 */
class ThermalReceiptController extends Controller
{
    public function __invoke(Payment $payment): View
    {
        $user = Auth::user();

        $authorized = $user->hasRole('Super Admin')
            || ($payment->shop_id !== null && $payment->shop_id === (Tenant::id() ?? $user->shop_id));

        abort_unless($authorized, 403);

        $payment->load(['agreement.shop', 'agreement.schedules', 'customer', 'receiver']);

        return view('receipts.thermal', ['payment' => $payment]);
    }
}
