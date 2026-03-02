<?php

namespace App\Services\KMS;

use App\Services\Http\HttpClient;
use Illuminate\Support\Str;
use RuntimeException;

class KMSTokenManager
{
    private string $tokenFile;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $namespace,
        private readonly string $tokenPathTemplate,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $user,
        private readonly string $pass
    ) {
        $this->tokenFile = storage_path('app/kms_token.json');
    }

    public function getAccessToken(?string $correlationId = null): string
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $state = $this->readTokenState();

        if ($this->isUsable($state)) {
            return (string) $state['access_token'];
        }

        if (is_array($state) && !empty($state['refresh_token'])) {
            $refreshed = $this->refreshToken((string) $state['refresh_token'], $correlationId);
            $this->writeTokenState($refreshed);
            return (string) $refreshed['access_token'];
        }

        $fresh = $this->passwordToken($correlationId);
        $this->writeTokenState($fresh);
        return (string) $fresh['access_token'];
    }

    public function smokeTest(?string $correlationId = null): void
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $token = $this->getAccessToken($correlationId);
        if ($token === '') {
            throw new RuntimeException("KMS token is empty. correlation_id={$correlationId}");
        }

        logger()->info('[SMOKE_KMS_TOKEN_OK]', [
            'correlation_id' => $correlationId,
        ]);
    }

    private function tokenUrl(): string
    {
        $path = str_replace('{namespace}', $this->namespace, $this->tokenPathTemplate);
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function passwordToken(string $correlationId): array
    {
        $url = $this->tokenUrl();

        $response = $this->http->requestJson(
            'GET',
            $url,
            headers: ['Accept' => 'application/json'],
            jsonBody: [],
            query: [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->user,
                'password' => $this->pass,
            ],
            correlationId: $correlationId
        );

        $data = $response->json();
        if (!is_array($data) || empty($data['access_token'])) {
            $snippet = mb_substr((string) $response->body(), 0, 500);
            logger()->error('[KMS_TOKEN_BAD_RESPONSE]', [
                'correlation_id' => $correlationId,
                'body_snippet' => $snippet,
            ]);
            throw new RuntimeException("KMS password token response invalid. correlation_id={$correlationId}");
        }

        return $this->normalize($data);
    }

    private function refreshToken(string $refreshToken, string $correlationId): array
    {
        $url = $this->tokenUrl();

        $response = $this->http->requestJson(
            'GET',
            $url,
            headers: ['Accept' => 'application/json'],
            jsonBody: [],
            query: [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $refreshToken,
            ],
            correlationId: $correlationId
        );

        $data = $response->json();
        if (!is_array($data) || empty($data['access_token'])) {
            logger()->warning('[KMS_TOKEN_REFRESH_FAILED]', [
                'correlation_id' => $correlationId,
            ]);

            // Fallback naar password flow (geen infinite retry hier; HttpClient doet retries al)
            return $this->passwordToken($correlationId);
        }

        return $this->normalize($data);
    }

    private function normalize(array $data): array
    {
        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        $expiresAt = time() + max(0, $expiresIn - 60); // 60s marge

        return [
            'access_token' => (string) ($data['access_token'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at' => $expiresAt,
        ];
    }

    private function isUsable(?array $state): bool
    {
        if (!is_array($state)) return false;
        if (empty($state['access_token'])) return false;
        if (empty($state['expires_at'])) return false;
        return (int) $state['expires_at'] > time();
    }

    private function readTokenState(): ?array
    {
        if (!file_exists($this->tokenFile)) return null;

        $raw = @file_get_contents($this->tokenFile);
        if ($raw === false || $raw === '') return null;

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeTokenState(array $state): void
    {
        @file_put_contents($this->tokenFile, json_encode($state, JSON_PRETTY_PRINT));
    }
}
