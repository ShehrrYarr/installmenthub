<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\View\View;

class ThermalReceiptController extends Controller
{
    public function __invoke(Payment $payment): View
    {
        $payment->load(['agreement.shop', 'agreement.schedules', 'customer', 'receiver']);

        return view('receipts.thermal', ['payment' => $payment]);
    }
}
