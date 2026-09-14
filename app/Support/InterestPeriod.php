<?php

namespace App\Support;

/**
 * A flat interest rate only means something alongside the stretch of time it
 * covers. Shops quote differently — 12% a year, 2% a month — so the period is
 * a per-shop setting (Settings → EMI Settings) and is copied onto every
 * agreement, so a rate stays readable after the shop changes its basis.
 */
final class InterestPeriod
{
    public const DEFAULT_MONTHS = 12;

    public const MIN_MONTHS = 1;

    public const MAX_MONTHS = 120;

    public static function rule(): string
    {
        return 'required|integer|min:'.self::MIN_MONTHS.'|max:'.self::MAX_MONTHS;
    }

    /** "per year", "per month", "per 6 months". */
    public static function label(?int $months): string
    {
        return match ((int) $months) {
            1 => 'per month',
            12 => 'per year',
            default => 'per '.(int) $months.' months',
        };
    }

    /** "annual %", "monthly %", "% per 6 months" — for a form field label. */
    public static function fieldLabel(?int $months): string
    {
        return match ((int) $months) {
            1 => 'Interest Rate (monthly %)',
            12 => 'Interest Rate (annual %)',
            default => 'Interest Rate (% per '.(int) $months.' months)',
        };
    }

    /**
     * The same cost expressed against a different period. A shop moving from
     * 12% a year to a 6-month basis should end up on 6%, not keep 12 and
     * silently double what every new customer pays.
     */
    public static function convertRate(string $rate, int $fromMonths, int $toMonths): string
    {
        if ($fromMonths < 1 || $toMonths < 1 || $fromMonths === $toMonths) {
            return $rate;
        }

        $converted = bcdiv(bcmul($rate, (string) $toMonths, 6), (string) $fromMonths, 4);

        return rtrim(rtrim($converted, '0'), '.') ?: '0';
    }
}
