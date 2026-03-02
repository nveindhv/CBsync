<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TokenService
{
    public function getToken(): ?string
    {
        // Prefer explicit config, but fall back to .env defaults used in this project.
        $baseUrl = rtrim((string) (config('services.kms.base_url') ?? env('KMS_BASE_URL', 'https://www.twensokms.nl')), '/');
        $namespace = (string) (config('services.kms.namespace') ?? env('KMS_NAMESPACE', ''));
        $namespace = trim($namespace, '/');

        $tokenUrl = config('services.kms.token_url');
        if (!is_string($tokenUrl) || trim($tokenUrl) === '') {
            if ($namespace === '') {
                return null;
            }
            // Per KMS implementatie-handleiding:
            // GET https://www.twensokms.nl/oauth/{namespace}/v2/token
            $tokenUrl = $baseUrl . '/oauth/' . $namespace . '/v2/token';
        }

        $clientId = (string) (config('services.kms.client_id') ?? env('KMS_CLIENT_ID', ''));
        $clientSecret = (string) (config('services.kms.client_secret') ?? env('KMS_CLIENT_SECRET', ''));
        $username = (string) (config('services.kms.username') ?? env('KMS_USER', ''));
        $password = (string) (config('services.kms.password') ?? env('KMS_PASS', ''));

        if ($clientId === '' || $clientSecret === '' || $username === '' || $password === '') {
            return null;
        }

        $response = Http::withOptions(['verify' => false])
            ->timeout(60)
            ->get($tokenUrl, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'username' => $username,
                'password' => $password,
                'grant_type' => 'password',
            ]);

        if (!$response->ok()) {
            return null;
        }

        $json = $response->json();
        return is_array($json) ? ($json['access_token'] ?? null) : null;
    }
}
