<?php

namespace App\Livewire\Concerns;

use App\Support\ReceiptProof;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\HasMedia;

/**
 * Form-side half of HasReceiptProof: holds the pending upload, validates it,
 * and moves it onto the record once that record exists.
 *
 * Pair with Livewire\WithFileUploads. The field itself is rendered by
 * x-payment-method-select, which only shows it once Bank is chosen.
 */
trait HandlesReceiptProof
{
    public ?TemporaryUploadedFile $receiptProof = null;

    /** Set when an already-saved proof should be dropped on the next save. */
    public bool $receiptProofCleared = false;

    public function removeReceiptProof(): void
    {
        $this->reset('receiptProof');
        $this->receiptProofCleared = true;
    }

    /** @return array<string, string> */
    protected function receiptProofRules(): array
    {
        return ['receiptProof' => ReceiptProof::validationRule()];
    }

    /**
     * Cash leaves no slip, so a proof carried over from a moment when Bank was
     * selected is dropped rather than quietly stored against a cash record.
     */
    protected function storeReceiptProof(HasMedia $record): void
    {
        $collection = ReceiptProof::COLLECTION;

        if (! $record->paidByBank()) {
            $record->clearMediaCollection($collection);
            $this->reset('receiptProof', 'receiptProofCleared');

            return;
        }

        if ($this->receiptProofCleared && ! $this->receiptProof) {
            $record->clearMediaCollection($collection);
        }

        if ($this->receiptProof) {
            // singleFile() on the collection replaces whatever was there.
            $record->addMedia($this->receiptProof->getRealPath())
                ->usingFileName($this->receiptProof->getClientOriginalName())
                ->toMediaCollection($collection);
        }

        $this->reset('receiptProof', 'receiptProofCleared');
    }
}
