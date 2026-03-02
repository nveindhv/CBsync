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
        // Prevent TypeError when config returns null on typed properties.
        // Prefer services.kms.* (used by existing KMS commands), but fall back to config/kms.php and env defaults.
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

        // Token service already works in your app (kms:token:test), so keep using it.
        $this->token = app(TokenService::class)->getToken();

        $this->baseUrl = rtrim($this->baseUrl, '/');

        if ($this->baseUrl === '') {
            throw new RuntimeException('KMS base URL missing. Set KMS_BASE_URL or services.kms.base_url');
        }
        if ($this->namespace === '') {
            throw new RuntimeException('KMS namespace missing. Set KMS_NAMESPACE or services.kms.namespace');
        }
    }

    private function pending()
    {
        return Http::timeout(90)->acceptJson()->withHeaders([
            'access_token' => $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/rest/' . $this->namespace . '/' . ltrim($path, '/');
    }

    public function post(string $path, array $payload): array
    {
        $url = $this->url($path);

        Log::info('[KMS] Request', [
            'method' => 'POST',
            'url' => $url,
        ]);

        $resp = $this->pending()->post($url, $payload);

        return [
            'ok' => $resp->ok(),
            'status' => $resp->status(),
            'body' => $resp->json(),
        ];
    }

    /**
     * Mirror existing GET-command behavior: POST kms/product/getProducts with offset/limit and optional articleNumber.
     * Returns the raw decoded JSON body (array).
     */
    public function getProducts(?string $articleNumber, int $offset, int $limit, ?string $correlationId = null): array
    {
        $payload = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'articleNumber' => $articleNumber,
        ], fn($v) => $v !== null && $v !== '');

        $req = $this->pending();
        if ($correlationId) {
            $req = $req->withHeaders(['X-Correlation-Id' => $correlationId]);
        }

        $url = $this->url('kms/product/getProducts');

        $resp = $req->post($url, $payload);

        if (!$resp->ok()) {
            throw new RuntimeException('KMS getProducts failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json() ?? [];
    }

    public function getProductsList(?string $articleNumber, int $offset, int $limit, ?string $correlationId = null): array
    {
        return $this->getProducts($articleNumber, $offset, $limit, $correlationId);
    }
}
