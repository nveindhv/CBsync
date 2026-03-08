<?php

namespace App\Services\Kms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class KmsClient
{
    public function __construct(private readonly KmsAuth $auth)
    {
    }

    public function getAccessToken(): string
    {
        return $this->auth->getAccessToken();
    }

    private function request(?string $correlationId = null): PendingRequest
    {
        $token = $this->auth->getAccessToken();

        $headers = [
            'access_token' => $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (is_string($correlationId) && trim($correlationId) !== '') {
            $headers['X-Correlation-Id'] = trim($correlationId);
        }

        return Http::withToken($token)
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout((int) config('kms.timeout', 30));
    }

    public function post(string $relativePath, array $payload = [], ?string $correlationId = null): array
    {
        $baseUrl = rtrim((string) config('kms.base_url'), '/');
        $namespace = trim((string) config('kms.namespace'), '/');
        if ($namespace === '') {
            throw new \RuntimeException('KMS namespace missing');
        }

        $restPrefixTpl = (string) config('kms.rest_prefix', '/rest/{namespace}');
        $restPrefix = '/' . trim(str_replace('{namespace}', $namespace, $restPrefixTpl), '/');
        $relativePath = '/' . ltrim($relativePath, '/');
        $url = $baseUrl . $restPrefix . $relativePath;

        $response = $this->request($correlationId)->post($url, $payload);
        if ($response->status() === 401) {
            $this->auth->forgetToken();
            $response = $this->request($correlationId)->post($url, $payload);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("KMS request failed (POST {$relativePath}) HTTP {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
