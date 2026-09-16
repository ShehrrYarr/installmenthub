<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\Vendor;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer KYC documents and vendor documents live on the private disk and
 * are only ever served through here — staff of the shop that owns the
 * record, nothing wider. Unlike bank slips (see ReceiptProofController),
 * customers never see these, so there's no customer-guard branch here.
 */
class TenantDocumentController extends Controller
{
    private const ALLOWED_COLLECTIONS = [
        Customer::MEDIA_COLLECTION,
        Vendor::MEDIA_COLLECTION,
    ];

    /** $shop comes from the route prefix and is bound before {media} — both must be declared, in order. */
    public function __invoke(Shop $shop, Media $media, Request $request): StreamedResponse
    {
        abort_unless(in_array($media->collection_name, self::ALLOWED_COLLECTIONS, true), 404);

        $record = $media->model;

        abort_if($record === null, 404);

        $user = Auth::guard('web')->user();

        abort_unless($user, 403);

        $authorized = $user->hasRole('Super Admin')
            || ($record->shop_id !== null && $record->shop_id === (Tenant::id() ?? $user->shop_id));

        abort_unless($authorized, 403);

        return $media->toInlineResponse($request, (string) $request->query('conversion', ''));
    }
}
