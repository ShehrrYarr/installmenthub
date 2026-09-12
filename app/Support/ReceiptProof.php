<?php

namespace App\Support;

/**
 * Where a bank slip lives and what it may be.
 *
 * Its own class rather than a constant on HasReceiptProof because PHP won't
 * let a trait constant be read directly — and the Livewire form concern and
 * the streaming controller both need the collection name without a model in
 * hand.
 */
final class ReceiptProof
{
    public const COLLECTION = 'receipt_proof';

    /** @var array<int, string> */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public const MAX_KILOBYTES = 4096;

    public static function validationRule(): string
    {
        return 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:'.self::MAX_KILOBYTES;
    }
}
