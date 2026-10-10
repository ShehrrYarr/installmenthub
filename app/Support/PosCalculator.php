<?php

namespace App\Support;

/**
 * The arithmetic of a counter sale, in one place so the register's live
 * preview and the checkout that actually writes the sale can never disagree.
 *
 * All money is handled as bcmath strings at 2 decimals.
 */
final class PosCalculator
{
    public const FIXED = 'fixed';

    public const PERCENT = 'percent';

    /** @return array<int, string> */
    public static function discountTypes(): array
    {
        return [self::FIXED, self::PERCENT];
    }

    /**
     * The discount amount for a figure, never more than the figure itself and
     * never negative. Percentages round half up to the paisa.
     */
    public static function discount(string $base, string $type, string $value): string
    {
        $value = self::number($value);

        if (bccomp($value, '0', 2) <= 0 || bccomp($base, '0', 2) <= 0) {
            return '0.00';
        }

        $amount = $type === self::PERCENT
            ? bcadd(bcdiv(bcmul($base, $value, 6), '100', 6), '0.005', 2)
            : bcadd($value, '0', 2);

        return bccomp($amount, $base, 2) === 1 ? bcadd($base, '0', 2) : $amount;
    }

    /**
     * @param  array<int|string, array{unit_price: string, quantity: int, discount_type?: string, discount_value?: string}>  $lines
     * @return array{
     *     lines: array<int|string, array{gross: string, discount: string, net: string}>,
     *     subtotal: string,
     *     line_discount_total: string,
     *     bill_discount_amount: string,
     *     total: string,
     * }
     */
    public static function totals(array $lines, string $billDiscountType = self::FIXED, string $billDiscountValue = '0'): array
    {
        $computed = [];
        $subtotal = '0.00';
        $lineDiscounts = '0.00';

        foreach ($lines as $key => $line) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $gross = bcmul(self::number($line['unit_price'] ?? '0'), (string) $quantity, 2);
            $discount = self::discount($gross, $line['discount_type'] ?? self::FIXED, $line['discount_value'] ?? '0');

            $computed[$key] = [
                'gross' => $gross,
                'discount' => $discount,
                'net' => bcsub($gross, $discount, 2),
            ];

            $subtotal = bcadd($subtotal, $gross, 2);
            $lineDiscounts = bcadd($lineDiscounts, $discount, 2);
        }

        $afterLines = bcsub($subtotal, $lineDiscounts, 2);
        $billDiscount = self::discount($afterLines, $billDiscountType, $billDiscountValue);

        return [
            'lines' => $computed,
            'subtotal' => $subtotal,
            'line_discount_total' => $lineDiscounts,
            'bill_discount_amount' => $billDiscount,
            'total' => bcsub($afterLines, $billDiscount, 2),
        ];
    }

    /**
     * "Rs. 12,500" — and "Rs. 12,499.50" only when there are paisa to show,
     * which a percentage discount can produce.
     */
    public static function money(string|int|float $value): string
    {
        $value = (string) $value;
        $negative = str_starts_with($value, '-');
        $abs = self::number(ltrim($value, '-'));
        $whole = bccomp(bcmod($abs, '1', 2), '0', 2) === 0;

        return ($negative && bccomp($abs, '0', 2) > 0 ? '-' : '')
            .'Rs. '.number_format((float) $abs, $whole ? 0 : 2);
    }

    /** A form value as a bcmath-safe decimal string — blanks and junk read as zero. */
    public static function number(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        // A plain decimal only — bcmath rejects exponents ("1e5") and signs
        // are never meaningful for the amounts a register takes.
        if (! is_string($value) || ! preg_match('/^\d+(\.\d+)?$/', trim($value))) {
            return '0.00';
        }

        return bcadd(trim($value), '0', 2);
    }
}
