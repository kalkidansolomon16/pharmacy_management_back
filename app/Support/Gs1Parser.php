<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Parses GS1 DataMatrix / GS1-128 element strings, as required by EFDA on secondary packaging:
 *   (01) GTIN, (17) expiry YYMMDD, (10) batch/lot, (21) serial number.
 * Accepts the human-readable form "(01)08901234567890(17)271231(10)AB12" and the raw scanner
 * form where variable-length fields end with the GS separator (ASCII 29) or "]d2" symbology prefix.
 */
final class Gs1Parser
{
    private const GS = "\x1D";

    /** Fixed-length application identifiers. */
    private const FIXED = ['01' => 14, '17' => 6, '11' => 6, '15' => 6];

    /**
     * @return array{gtin: ?string, expiry: ?string, batch: ?string, serial: ?string, raw: string}|null
     *                                                                                                  null when the input is not a GS1 string (e.g. a plain EAN-13 barcode).
     */
    public static function parse(string $input): ?array
    {
        $raw = trim($input);
        $s = preg_replace('/^\]d2|^\]C1|^\]Q3/', '', $raw);

        $fields = [];
        if (str_contains($s, '(')) {
            preg_match_all('/\((\d{2,4})\)([^(]+)/', $s, $m, PREG_SET_ORDER);
            foreach ($m as [, $ai, $value]) {
                $fields[$ai] = trim($value, self::GS.' ');
            }
        } else {
            $s = str_replace(['<GS>', '{GS}', '~'], self::GS, $s);
            if (! preg_match('/^(01|17|10|21|11|15)/', $s)) {
                return null;
            }
            while ($s !== '' && $s !== false) {
                $ai = substr($s, 0, 2);
                $s = substr($s, 2);
                if (isset(self::FIXED[$ai])) {
                    $fields[$ai] = substr($s, 0, self::FIXED[$ai]);
                    $s = substr($s, self::FIXED[$ai]);
                } else {
                    $end = strpos($s, self::GS);
                    $fields[$ai] = $end === false ? $s : substr($s, 0, $end);
                    $s = $end === false ? '' : substr($s, $end + 1);
                }
                $s = ltrim((string) $s, self::GS);
            }
        }

        if (! isset($fields['01']) && ! isset($fields['10'])) {
            return null;
        }

        return [
            'gtin' => $fields['01'] ?? null,
            'expiry' => isset($fields['17']) ? self::expiry($fields['17']) : null,
            'batch' => $fields['10'] ?? null,
            'serial' => $fields['21'] ?? null,
            'raw' => $raw,
        ];
    }

    /** GS1 YYMMDD; day "00" means the last day of that month. */
    private static function expiry(string $yymmdd): ?string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }
        $date = Carbon::create(2000 + (int) $m[1], (int) $m[2], 1);

        return ((int) $m[3] === 0 ? $date->endOfMonth() : $date->setDay((int) $m[3]))->toDateString();
    }

    /** GTIN-14 and EAN-13 refer to the same product: compare without the leading packaging digit zeros. */
    public static function sameProduct(?string $a, ?string $b): bool
    {
        return $a && $b && ltrim($a, '0') === ltrim($b, '0');
    }
}
