<?php

namespace App\Traits;

use App\Support\PaymentMethod;
use App\Support\ReceiptProof;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A bank transfer leaves a slip; cash doesn't. Anything recorded against a
 * bank can carry one scanned proof, shown as a paperclip beside the amount
 * wherever the record surfaces.
 *
 * The file lives on the private disk and is only ever reached through
 * ReceiptProofController, which checks that the viewer is either staff of the
 * owning shop or the customer the money belongs to. Bank slips carry account
 * numbers, so they must not be guessable from a public URL the way the
 * customer KYC gallery is.
 */
trait HasReceiptProof
{
    // Carries InteractsWithMedia so a model only has to `use HasReceiptProof`;
    // the registerMediaCollections() below overrides Spatie's empty stub.
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(ReceiptProof::COLLECTION)
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(ReceiptProof::MIME_TYPES);
    }

    public function receiptProof(): ?Media
    {
        return $this->getFirstMedia(ReceiptProof::COLLECTION);
    }

    public function paidByBank(): bool
    {
        return $this->payment_mode === PaymentMethod::BANK;
    }

    /** A bank record with nothing attached — the state the ledger flags so it can be chased. */
    public function missingReceiptProof(): bool
    {
        return $this->paidByBank() && $this->receiptProof() === null;
    }
}
