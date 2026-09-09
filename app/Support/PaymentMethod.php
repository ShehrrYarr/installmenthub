<?php

namespace App\Support;

use App\Models\Bank;
use Illuminate\Support\Collection;

/**
 * Payment method inputs are a set of radios — Cash, Bank, and Credit on
 * purchase orders — with a bank dropdown that appears once Bank is chosen.
 * That maps directly onto the two stored columns: payment_mode and bank_id.
 */
class PaymentMethod
{
    public const CASH = 'cash';

    public const BANK = 'bank';

    public const CREDIT = 'credit';

    /** @return Collection<int, Bank> */
    public static function banks(): Collection
    {
        return Bank::where('is_active', true)->orderBy('name')->get();
    }

    /** @return array<int, string> */
    public static function methods(bool $withCredit = false): array
    {
        return $withCredit
            ? [self::CASH, self::BANK, self::CREDIT]
            : [self::CASH, self::BANK];
    }

    public static function methodRule(bool $withCredit = false): string
    {
        return 'required|in:'.implode(',', self::methods($withCredit));
    }

    /**
     * A bank is required only when the Bank radio is chosen, and must be one
     * of this shop's active banks — the id list is tenant-scoped, so another
     * shop's bank is rejected.
     */
    public static function bankRule(string $methodField): string
    {
        $ids = self::banks()->pluck('id')->implode(',');

        return "nullable|required_if:{$methodField},".self::BANK.($ids === '' ? '' : "|in:{$ids}");
    }

    /** @return array<string, string> */
    public static function bankMessages(string $bankField): array
    {
        return [
            "{$bankField}.required_if" => 'Please select a bank.',
            "{$bankField}.in" => 'Please select a bank.',
        ];
    }

    /**
     * The two form values for a stored record.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function forForm(?string $paymentMode, ?int $bankId): array
    {
        if ($bankId) {
            return [self::BANK, $bankId];
        }

        if (in_array($paymentMode, [self::CASH, self::CREDIT], true) || $paymentMode === null) {
            return [$paymentMode ?? self::CASH, null];
        }

        // Rows written before banks were configurable stored the method name
        // directly ('easypaisa', 'bank', …). The upgrade created a bank of the
        // same name for every shop, so match on that to keep the original
        // method selected when one of those records is edited.
        $legacyMatch = Bank::where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($paymentMode)])
            ->value('id');

        return $legacyMatch ? [self::BANK, (int) $legacyMatch] : [self::CASH, null];
    }

    /**
     * The two column values to store.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function toStorage(string $method, int|string|null $bankId): array
    {
        return $method === self::BANK
            ? [self::BANK, (int) $bankId]
            : [$method, null];
    }

    /** How a stored record should read — the bank's name, or the legacy text. */
    public static function label(?string $paymentMode, ?Bank $bank): ?string
    {
        if ($bank) {
            return $bank->name;
        }

        return $paymentMode ? ucfirst($paymentMode) : null;
    }
}
