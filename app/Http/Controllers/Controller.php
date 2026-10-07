<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Uniform envelope for action endpoints: { message, data }.
     * Read endpoints return API Resources ({ data, links?, meta? }) directly.
     */
    protected function respond(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'data' => $data,
        ], fn ($v) => $v !== null), $status);
    }

    protected function perPage(int $default = 15): int
    {
        return min(max((int) request('per_page', $default), 1), 100);
    }
}
