<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Prescription;
use Illuminate\Support\Str;

class ReferenceGenerator
{
    /** Unambiguous alphabet: no 0/O or 1/I, easy to read out over the phone. */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function prescriptionCode(): string
    {
        do {
            $code = 'RX-'.self::random(4).'-'.self::random(4);
        } while (Prescription::withoutGlobalScopes()->where('reference_code', $code)->exists());

        return $code;
    }

    public static function orderNumber(): string
    {
        do {
            $number = 'ORD-'.now()->format('ymd').'-'.self::random(5);
        } while (Order::withoutGlobalScopes()->where('order_number', $number)->exists());

        return $number;
    }

    private static function random(int $length): string
    {
        return collect(range(1, $length))
            ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
            ->implode('');
    }

    /**
     * Canonical Ethiopian mobile format: 0911223344 / 911223344 / 251911223344 -> +251911223344
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (Str::startsWith($digits, '0') && strlen($digits) === 10) {
            $digits = '251'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '251'.$digits;
        }

        return '+'.$digits;
    }
}
