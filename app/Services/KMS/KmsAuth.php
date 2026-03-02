<?php

namespace App\Services\Kms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class KmsAuth
{
    public function getAccessToken(): string
    {
        $cacheKey = 'kms.access_token';

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($cacheKey) {
            $baseUrl = rtrim((string) config('kms.base_url'), '/');
            $namespace = (string) config('kms.namespace');

            $tokenPathTpl = (string) config('kms.token_path');
            // token_path may contain {namespace}
            $tokenPath = str_replace('{namespace}', $namespace, $tokenPathTpl);
            $tokenPath = ltrim($tokenPath, '/');

            $response = Http::asForm()
                ->timeout((int) config('kms.timeout'))
                ->post("{$baseUrl}/{$tokenPath}", [
                    'grant_type' => 'password',
                    'client_id' => config('kms.client_id'),
                    'client_secret' => config('kms.client_secret'),
                    'username' => config('kms.username'),
                    'password' => config('kms.password'),
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException("KMS token request failed (HTTP {$response->status()}): {$response->body()}");
            }

            $json = $response->json();
            if (!is_array($json) || empty($json['access_token'])) {
                throw new \RuntimeException('KMS token response did not include access_token.');
            }

            // Respect expires_in if present (keep a small safety margin)
            if (!empty($json['expires_in']) && is_numeric($json['expires_in'])) {
                $ttl = max(60, ((int) $json['expires_in']) - 60);
                Cache::put($cacheKey, $json['access_token'], now()->addSeconds($ttl));
            }

            return (string) $json['access_token'];
        });
    }

    public function forgetToken(): void
    {
        Cache::forget('kms.access_token');
    }
}
