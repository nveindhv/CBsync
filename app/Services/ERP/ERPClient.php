<?php

namespace App\Services\ERP;

use App\Services\Http\HttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class ERPClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $apiBasePath,
        private readonly string $admin,
        private readonly string $user,
        private readonly string $pass
    ) {}

    /**
     * Generic ERP collection GET:
     * GET {ERP_BASE_URL}{ERP_API_BASE_PATH}/{admin}/{resource}?offset={offset}&limit={limit}
     *
     * Many ERP endpoints also support optional query params:
     * - select (string)
     * - filter (string)
     */
    public function getResourcePage(
        string $resource,
        int $offset,
        int $limit,
        ?string $correlationId = null,
        ?string $select = null,
        ?string $filter = null,
        array $extraQuery = []
    ): array {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $resource = trim($resource);
        if ($resource === '') {
            throw new RuntimeException('ERP resource is empty');
        }

        $url = rtrim($this->baseUrl, '/') . $this->apiBasePath . '/' . $this->admin . '/' . ltrim($resource, '/');

        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->pass),
        ];

        $query = array_merge([
            'offset' => $offset,
            'limit' => $limit,
        ], $extraQuery);

        $select = is_null($select) ? '' : trim($select);
        $filter = is_null($filter) ? '' : trim($filter);

        // Only send if explicitly set (avoid assumptions)
        if ($select !== '') {
            $query['select'] = $select;
        }
        if ($filter !== '') {
            $query['filter'] = $filter;
        }

        $response = $this->http->requestJson(
            'GET',
            $url,
            $headers,
            jsonBody: [],
            query: $query,
            correlationId: $correlationId
        );

        return $this->decodeJson($response, $correlationId, 'ERP getResourcePage');
    }

    public function smokeTest(string $resource = 'products', ?string $correlationId = null): void
    {
        $correlationId = $correlationId ?: (string) Str::uuid();

        $data = $this->getResourcePage($resource, 0, 10, $correlationId);

        logger()->info('[SMOKE_ERP_OK]', [
            'correlation_id' => $correlationId,
            'resource' => $resource,
            'received_count_guess' => $this->guessCount($data),
        ]);
    }

    private function decodeJson(Response $response, string $correlationId, string $context): array
    {
        $json = $response->json();

        if (!is_array($json)) {
            $snippet = mb_substr((string) $response->body(), 0, 500);
            logger()->error('[ERP_BAD_JSON]', [
                'correlation_id' => $correlationId,
                'context' => $context,
                'body_snippet' => $snippet,
            ]);
            throw new RuntimeException("ERP returned non-JSON response. correlation_id={$correlationId}");
        }

        return $json;
    }

    private function guessCount(array $data): int
    {
        // Best-effort, no assumptions about response-shape.
        if (isset($data['data']) && is_array($data['data'])) {
            return count($data['data']);
        }
        if (isset($data['items']) && is_array($data['items'])) {
            return count($data['items']);
        }
        if (array_is_list($data)) {
            return count($data);
        }

        // Fallback: try first array-like child
        foreach ($data as $v) {
            if (is_array($v) && array_is_list($v)) {
                return count($v);
            }
        }

        return 0;
    }
}
