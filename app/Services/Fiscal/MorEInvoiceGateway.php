<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Ministry of Revenue EIRS adapter.
 *
 * IMPORTANT: the endpoint paths and field names here follow common e-invoicing conventions and must be
 * aligned with the Ministry's official API specification during accreditation. The rest of the system
 * (numbering, IRN, tax, QR, retry queue) does not depend on those details.
 */
class MorEInvoiceGateway implements EInvoiceGateway
{
    public function register(array $payload): array
    {
        $config = config('einvoice.mor');
        if (empty($config['endpoint']) || empty($config['client_id'])) {
            throw new EInvoiceUnavailable('Ministry of Revenue e-invoicing is not configured (EINVOICE_MOR_* settings).');
        }

        try {
            $response = Http::timeout($config['timeout'])
                ->withToken($this->token($config))
                ->acceptJson()
                ->post(rtrim($config['endpoint'], '/').'/invoices', $payload + ['device_id' => $config['device_id']]);
        } catch (ConnectionException $e) {
            throw new EInvoiceUnavailable('Cannot reach the Ministry of Revenue: '.$e->getMessage());
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new EInvoiceUnavailable('Ministry of Revenue temporarily unavailable (HTTP '.$response->status().').');
        }
        if ($response->failed()) {
            throw new EInvoiceRejected($response->json('message') ?? 'Rejected (HTTP '.$response->status().').');
        }

        return [
            'rrn' => $response->json('rrn') ?? $response->json('data.rrn'),
            'registered_at' => $response->json('registered_at') ?? now()->toIso8601String(),
            'status' => $response->json('status', 'ACCEPTED'),
            'environment' => 'production',
        ];
    }

    private function token(array $config): string
    {
        return Cache::remember('einvoice.mor.token', now()->addMinutes(50), function () use ($config) {
            $response = Http::timeout($config['timeout'])->asForm()->post(rtrim($config['endpoint'], '/').'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ]);
            if ($response->failed()) {
                throw new EInvoiceUnavailable('Ministry of Revenue authentication failed.');
            }

            return (string) $response->json('access_token');
        });
    }

    public function name(): string
    {
        return 'mor';
    }
}
