<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Gregorian -> Ethiopian (Amete Mihret) conversion, and the Ethiopian fiscal year
 * (EFY runs Hamle 1 - Sene 30, i.e. 8 July - 7 July; EFY 2019 ends in Sene 2019 E.C.).
 */
final class EthiopianCalendar
{
    private const JD_EPOCH = 1723856;

    public const MONTHS = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazya', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

    public const MONTHS_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];

    /** @return array{year: int, month: int, day: int} */
    public static function fromGregorian(CarbonInterface $date): array
    {
        $jdn = self::julianDay((int) $date->year, (int) $date->month, (int) $date->day);
        $r = ($jdn - self::JD_EPOCH) % 1461;
        $n = ($r % 365) + 365 * intdiv($r, 1460);

        return [
            'year' => 4 * intdiv($jdn - self::JD_EPOCH, 1461) + intdiv($r, 365) - intdiv($r, 1460),
            'month' => intdiv($n, 30) + 1,
            'day' => ($n % 30) + 1,
        ];
    }

    /** Julian Day Number of a Gregorian date (no ext-calendar dependency). */
    private static function julianDay(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $y = $y + 4800 - $a;
        $m = $m + 12 * $a - 3;

        return $d + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }

    public static function format(CarbonInterface $date, bool $amharic = false): string
    {
        ['year' => $y, 'month' => $m, 'day' => $d] = self::fromGregorian($date);

        return $amharic ? self::MONTHS_AM[$m - 1]." {$d} ቀን {$y} ዓ.ም." : self::MONTHS[$m - 1]." {$d}, {$y} E.C.";
    }

    /** Fiscal year number: from Hamle (month 11) the next EFY has started. */
    public static function fiscalYear(?CarbonInterface $date = null): int
    {
        ['year' => $y, 'month' => $m] = self::fromGregorian($date ?? Carbon::now());

        return $m >= 11 ? $y + 1 : $y;
    }
}
