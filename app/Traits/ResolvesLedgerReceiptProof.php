<?php

namespace App\Traits;

use App\Support\PaymentMethod;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A ledger row either carries its own bank details (a manual Cash In/Out) or
 * inherits them from the document that generated it — a Payment for a
 * collection, a PurchaseOrder for stock bought on bank transfer. The proof
 * follows the same path, so the paperclip shows up on the row either way.
 */
trait ResolvesLedgerReceiptProof
{
    public function resolvedPaymentMode(): ?string
    {
        return $this->payment_mode ?? $this->reference?->payment_mode;
    }

    public function resolvedReceiptProof(): ?Media
    {
        if ($proof = $this->receiptProof()) {
            return $proof;
        }

        $reference = $this->reference;

        return $reference instanceof HasMedia && method_exists($reference, 'receiptProof')
            ? $reference->receiptProof()
            : null;
    }

    /** Paid by bank with nothing attached — flagged on the row so it can be chased. */
    public function needsReceiptProof(): bool
    {
        return $this->resolvedPaymentMode() === PaymentMethod::BANK
            && $this->resolvedReceiptProof() === null;
    }
}
