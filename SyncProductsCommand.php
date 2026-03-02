<?php

namespace App\Services\ERP;

use App\Services\Http\HttpClient;
use Illuminate\Support\Str;

class ERPClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $admin,
        private readonly string $authHeader,
        private readonly string $authValue,
        private readonly string $productsPathTemplate
    ) {}

    public function fetchProducts(int $offset, int $limit, string $select, ?string $correlationId = null): array
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $path = str_replace('{admin}', $this->admin, $this->productsPathTemplate);
        $url = rtrim($this->baseUrl, '/') . $path;

        $headers = ['Accept' => 'application/json'];
        if ($this->authValue !== '') {
            $headers[$this->authHeader] = $this->authValue;
        }

        $resp = $this->http->requestJson(
            'GET',
            $url,
            headers: $headers,
            query: [
                'offset' => $offset,
                'limit' => $limit,
                'select' => $select,
            ],
            jsonBody: [],
            correlationId: $correlationId
        );

        $data = $resp->json();
        return is_array($data) ? $data : [];
    }
}
