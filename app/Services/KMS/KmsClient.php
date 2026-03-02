<?php

namespace App\Services\Kms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class KmsClient
{
    public function __construct(private readonly KmsAuth $auth)
    {
    }

    /**
     * Convenience passthrough so commands can call $client->getAccessToken().
     */
    public function getAccessToken(): string
    {
        return $this->auth->getAccessToken();
    }

    /**
     * Build the base request with auth header.
     *
     * Note:
     * - Historically this project used Bearer auth (Http::withToken).
     * - KMS docs/demo also accept an 'access_token' header.
     *
     * To keep backward compatibility (GET commands already working) AND align with
     * the docs, we send BOTH.
     */
    private function request(): PendingRequest
    {
        $token = $this->auth->getAccessToken();

        return Http::withToken($token)
            ->withHeaders([
                'access_token' => $token,
            ])
            ->acceptJson()
            ->timeout((int) config('kms.timeout'));
    }

    /**
     * POST to a KMS endpoint under the configured REST prefix.
     *
     * Example:
     *   {base_url}/rest/{namespace}/kms/product/getProducts
     */
    public function post(string $relativePath, array $payload = []): array
    {
        $baseUrl = rtrim((string) config('kms.base_url'), '/');
        $namespace = trim((string) config('kms.namespace'), '/');

        if ($namespace === '') {
            throw new \RuntimeException('KMS_NAMESPACE is empty. Set it in .env (e.g. democomfortbest).');
        }

        $restPrefixTpl = (string) config('kms.rest_prefix');
        $restPrefix = str_replace('{namespace}', $namespace, $restPrefixTpl);
        $restPrefix = '/' . trim($restPrefix, '/');

        $relativePath = '/' . ltrim($relativePath, '/');
        $url = $baseUrl . $restPrefix . $relativePath;

        $response = $this->request()->post($url, $payload);

        // If the token expired, clear cache and retry once.
        if ($response->status() === 401) {
            $this->auth->forgetToken();
            $response = $this->request()->post($url, $payload);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("KMS request failed (POST {$relativePath}) HTTP {$response->status()}: {$response->body()}");
        }

        $json = $response->json();

        // Some KMS endpoints return an array root, others a dict.
        return $json ?? [];
    }
}
