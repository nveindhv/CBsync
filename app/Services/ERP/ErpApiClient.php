<?php

namespace App\Services\ERP;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ErpApiClient
{
    /**
     * Perform a GET request to ERP.
     *
     * @param string $endpoint Example: 'products' or 'products/123' or 'products?limit=50'
     * @param array<string,mixed> $query
     */
    public function get(string $endpoint, array $query = []): Response
    {
        $baseUrl = rtrim((string) config('erp.base_url', env('ERP_BASE_URL', '')), '/');
        $apiBasePath = '/' . trim((string) config('erp.api_base_path', env('ERP_API_BASE_PATH', '')), '/');
        $admin = trim((string) config('erp.admin', env('ERP_ADMIN', '01')), '/');

        $user = (string) config('erp.user', env('ERP_USER', ''));
        $pass = (string) config('erp.pass', env('ERP_PASS', ''));

        // Allow passing query string in endpoint.
        $endpoint = ltrim($endpoint, '/');

        $url = $baseUrl . $apiBasePath . '/' . $admin . '/' . $endpoint;

        return Http::withBasicAuth($user, $pass)
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 250)
            ->get($url, $query);
    }
}
