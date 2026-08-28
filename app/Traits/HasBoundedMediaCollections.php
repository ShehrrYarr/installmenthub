<?php

namespace App\Traits;

/**
 * Shared helper for models whose media collections are capped (5 files —
 * see product_gallery, customer_documents, vendor_documents). Enforcement
 * lives here so both the model layer and the Livewire dropzone agree on the limit.
 */
trait HasBoundedMediaCollections
{
    public function remainingMediaSlots(string $collection, int $max = 5): int
    {
        return max(0, $max - $this->getMedia($collection)->count());
    }

    public function hasReachedMediaLimit(string $collection, int $max = 5): bool
    {
        return $this->getMedia($collection)->count() >= $max;
    }
}
