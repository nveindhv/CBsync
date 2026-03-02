<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ErpClient
{
    public function fetchFirstFiveProducts(): array
    {
        $baseUrl = rtrim((string) config('services.erp.base_url'), '/');
        $basePath = '/' . ltrim((string) config('services.erp.api_base_path'), '/'); // e.g. /test/rest/api/v1
        $admin   = trim((string) config('services.erp.admin'));
        $user    = (string) config('services.erp.username');
        $pass    = (string) config('services.erp.password');

        $verify  = (bool) config('services.erp.verify_ssl', true);
        $timeout = (int) config('services.erp.timeout', 30);

        if ($baseUrl === '' || $basePath === '/' || $admin === '') {
            throw new RuntimeException('ERP config missing: ERP_BASE_URL, ERP_API_BASE_PATH and/or ERP_ADMIN');
        }

        $url = "{$baseUrl}{$basePath}/{$admin}/products";

        $query = [
            'offset' => 0,
            'limit'  => 5,
            'select' => (string) config('services.erp.select'),
        ];

        Log::info('[ERP] Request', [
            'method' => 'GET',
            'url' => $url,
            'query' => $query,
            'verify_ssl' => $verify,
        ]);

        $resp = Http::timeout($timeout)
            ->withOptions(['verify' => $verify])
            ->when($user !== '' || $pass !== '', fn ($h) => $h->withBasicAuth($user, $pass))
            ->acceptJson()
            ->get($url, $query);

        Log::info('[ERP] Response', [
            'status' => $resp->status(),
        ]);

        if (!$resp->ok()) {
            throw new RuntimeException('ERP request failed (HTTP '.$resp->status().')');
        }

        $data = $resp->json();

        if (is_array($data) && array_key_exists('items', $data) && is_array($data['items'])) {
            return $data['items'];
        }

        if (is_array($data)) {
            return array_is_list($data) ? $data : array_values($data);
        }

        return [];
    }
}
