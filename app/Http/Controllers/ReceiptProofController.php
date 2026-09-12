<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\ReceiptProof;
use Illuminate\Support\Facades\Auth;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bank slips live on the private disk and are only ever served through here.
 * They carry account numbers, so unlike the customer KYC gallery they must
 * not be reachable by anyone who happens to hold the URL.
 *
 * Two kinds of viewer are allowed: staff of the shop that owns the record,
 * and — for money the customer themselves paid — that customer in the portal.
 */
class ReceiptProofController extends Controller
{
    /** $shop comes from the route prefix and is bound before {media} — both must be declared, in order. */
    public function __invoke(Shop $shop, Media $media): StreamedResponse
    {
        abort_unless($media->collection_name === ReceiptProof::COLLECTION, 404);

        $record = $media->model;

        abort_if($record === null, 404);
        abort_unless($this->staffMayView($record) || $this->customerMayView($record), 403);

        return $media->toInlineResponse(request());
    }

    /** Staff see anything belonging to the shop they're signed in to. */
    private function staffMayView(mixed $record): bool
    {
        $user = Auth::guard('web')->user();

        if (! $user) {
            return false;
        }

        return $user->hasRole('Super Admin')
            || ($record->shop_id !== null && $record->shop_id === (Tenant::id() ?? $user->shop_id));
    }

    /**
     * A customer sees proof attached to their own money only — their payments
     * and their own ledger rows. Everything else (expenses, purchase orders,
     * another customer's records) is not theirs to open.
     */
    private function customerMayView(mixed $record): bool
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            return false;
        }

        return match (true) {
            $record instanceof Payment, $record instanceof CustomerLedgerEntry => $record->customer_id === $customer->id,
            default => false,
        };
    }
}
