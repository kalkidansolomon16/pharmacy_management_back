<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Ethiopian indirect tax on a sale line.
 *
 * - VAT-registered sellers charge 15% VAT on standard-rated items.
 * - Others pay Turnover Tax (TOT): 2% on goods, 10% on services.
 * - Exempt supplies (most pharmaceuticals and medical services) carry no tax.
 * Prices are stored tax-exclusive; the tax is added on top.
 */
final class TaxCalculator
{
    public static function rate(Tenant $seller, string $taxCategory, bool $isService = false): float
    {
        if ($taxCategory !== 'standard') {
            return 0.0;
        }

        return match ($seller->tax_regime) {
            'vat' => (float) config('ethiopia.tax.vat_rate'),
            'tot' => (float) ($isService ? config('ethiopia.tax.tot_services_rate') : config('ethiopia.tax.tot_goods_rate')),
            default => 0.0,
        };
    }

    /** @return array{rate: float, tax: float, total: float} */
    public static function line(Tenant $seller, float $net, string $taxCategory, bool $isService = false): array
    {
        $rate = self::rate($seller, $taxCategory, $isService);
        $tax = round($net * $rate, 2);

        return ['rate' => $rate, 'tax' => $tax, 'total' => round($net + $tax, 2)];
    }

    public static function label(Tenant $seller): string
    {
        return match ($seller->tax_regime) {
            'vat' => 'VAT 15%',
            'tot' => 'TOT',
            default => 'Tax',
        };
    }
}
