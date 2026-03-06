<?php

namespace App\Services\Kms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class KmsAuth
{
    public function getAccessToken(): string
    {
        $cacheKey = 'kms_access_token:' . md5(json_encode([
            config('kms.base_url'),
            config('kms.namespace'),
            config('kms.username'),
            config('kms.client_id'),
        ]));

        return Cache::remember($cacheKey, now()->addMinutes(50), function () {
            $baseUrl = rtrim((string) config('kms.base_url'), '/');
            $namespace = trim((string) config('kms.namespace'), '/');
            $tokenPathTpl = (string) config('kms.token_path');
            $tokenPath = str_replace('{namespace}', $namespace, $tokenPathTpl);
            $url = $baseUrl . '/' . ltrim($tokenPath, '/');

            $payload = [
                'grant_type' => 'password',
                'client_id' => (string) config('kms.client_id'),
                'client_secret' => (string) config('kms.client_secret'),
                'username' => (string) config('kms.username'),
                'password' => (string) config('kms.password'),
            ];

            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('kms.timeout', 30))
                ->post($url, $payload);

            if (! $response->successful()) {
                throw new \RuntimeException('KMS token request failed HTTP ' . $response->status() . ': ' . $response->body());
            }

            $json = $response->json() ?? [];
            $token = $json['access_token'] ?? null;
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('KMS token response missing access_token');
            }

            return $token;
        });
    }

    public function forgetToken(): void
    {
        $cacheKey = 'kms_access_token:' . md5(json_encode([
            config('kms.base_url'),
            config('kms.namespace'),
            config('kms.username'),
            config('kms.client_id'),
        ]));

        Cache::forget($cacheKey);
    }
}
