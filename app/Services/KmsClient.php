<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KmsClient
{
    protected string $baseUrl;
    protected string $namespace;
    protected string $token;

    public function __construct()
    {
        $this->baseUrl = (string) (
            config('services.kms.base_url')
            ?? config('kms.base_url')
            ?? env('KMS_BASE_URL', 'https://www.twensokms.nl')
        );

        $this->namespace = (string) (
            config('services.kms.namespace')
            ?? config('kms.namespace')
            ?? env('KMS_NAMESPACE', '')
        );

        $this->token = app(TokenService::class)->getToken();
        $this->baseUrl = rtrim($this->baseUrl, '/');

        if ($this->baseUrl === '') {
            throw new RuntimeException('KMS base URL missing. Set KMS_BASE_URL or services.kms.base_url');
        }

        if ($this->namespace === '') {
            throw new RuntimeException('KMS namespace missing. Set KMS_NAMESPACE or services.kms.namespace');
        }
    }

    private function pending(?string $correlationId = null)
    {
        $headers = [
            'access_token' => $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (is_string($correlationId) && trim($correlationId) !== '') {
            $headers['X-Correlation-Id'] = trim($correlationId);
        }

        return Http::timeout(90)
            ->acceptJson()
            ->withHeaders($headers);
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/rest/' . $this->namespace . '/' . ltrim($path, '/');
    }

    /**
     * @return array{ok:bool,status:int,body:mixed}
     */
    public function post(string $path, array $payload = [], ?string $correlationId = null): array
    {
        $url = $this->url($path);

        Log::info('[KMS] Request', [
            'method' => 'POST',
            'url' => $url,
            'correlation_id' => $correlationId,
        ]);

        $resp = $this->pending($correlationId)->post($url, $payload);

        return [
            'ok' => $resp->ok(),
            'status' => $resp->status(),
            'body' => $resp->json() ?? $resp->body(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getProducts(?string $articleNumber, int $offset, int $limit, ?string $correlationId = null): array
    {
        $payload = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'articleNumber' => $articleNumber,
        ], fn ($v) => $v !== null && $v !== '');

        $url = $this->url('kms/product/getProducts');
        $resp = $this->pending($correlationId)->post($url, $payload);

        if (! $resp->ok()) {
            throw new RuntimeException('KMS getProducts failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json() ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getProductsList(?string $articleNumber, int $offset, int $limit, ?string $correlationId = null): array
    {
        return $this->getProducts($articleNumber, $offset, $limit, $correlationId);
    }
}
