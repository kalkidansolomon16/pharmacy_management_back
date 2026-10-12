<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Small geo helpers for "near me" searches. A bounding box narrows rows in SQL with plain
 * comparisons (portable across MySQL / MariaDB / SQLite); exact distances are computed in PHP.
 */
final class Geo
{
    /** Haversine distance between two points, in kilometres. */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Restrict a tenants query to the box around a point (rows without coordinates drop out). */
    public static function withinBox(Builder $query, float $lat, float $lng, float $radiusKm, string $prefix = ''): Builder
    {
        $latDelta = $radiusKm / 111.32;
        $lngDelta = $radiusKm / (111.32 * max(cos(deg2rad($lat)), 0.01));

        return $query
            ->whereBetween($prefix.'latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween($prefix.'longitude', [$lng - $lngDelta, $lng + $lngDelta]);
    }

    /** Distance from a point to something with latitude/longitude, or null when it has no location. */
    public static function from(?array $origin, $place): ?float
    {
        if (! $origin || $place?->latitude === null || $place?->longitude === null) {
            return null;
        }

        return round(self::distanceKm($origin['lat'], $origin['lng'], (float) $place->latitude, (float) $place->longitude), 2);
    }
}
