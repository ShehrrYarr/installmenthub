<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Printable A4 invoice for one agreement — which sections appear is
 * governed by the owning shop's Settings → Invoice preferences (see
 * resources/views/livewire/settings/invoice.blade.php).
 *
 * Shop Admin and Manager only, mirrored explicitly here rather than left to
 * ResolveTenant (which only checks shop ownership, not role) — same pattern
 * as ThermalReceiptController/CollectionBookPrintController.
 */
class AgreementInvoiceController extends Controller
{
    /**
     * $shop is otherwise unused — ShopScope already confines the {agreement}
     * binding to the current tenant — but it must stay in the signature: this
     * route lives under /s/{shop}/..., and Laravel positionally binds every
     * route-model param in URI order when dispatching a controller callable.
     */
    public function __invoke(Shop $shop, Agreement $agreement): View
    {
        $user = Auth::user();

        $authorized = $user->hasRole('Super Admin')
            || ($user->hasAnyRole(['Shop Admin', 'Manager'])
                && $agreement->shop_id === (Tenant::id() ?? $user->shop_id));

        abort_unless($authorized, 403);

        $agreement->load([
            'shop', 'customer', 'salesman', 'approver', 'guarantors',
            'items.product',
            'schedules' => fn ($q) => $q->orderBy('installment_number'),
        ]);

        return view('invoices.agreement', [
            'agreement' => $agreement,
            'shop' => $agreement->shop,
        ]);
    }
}
