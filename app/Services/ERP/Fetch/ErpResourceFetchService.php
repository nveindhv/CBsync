<?php

namespace App\Services\ERP\Fetch;

use App\Services\ERP\Dump\ErpDumpWriter;
use App\Services\ERP\ERPClient;
use Illuminate\Support\Str;

class ErpResourceFetchService
{
    public function __construct(
        private readonly ERPClient $erp,
        private readonly ErpDumpWriter $dumpWriter,
        private readonly int $defaultLimit = 200,
        private readonly int $defaultMaxPages = 1
    ) {}

    public function fetchAndDump(
        string $resource,
        int $limit,
        int $maxPages,
        ?string $select = null,
        ?string $filter = null,
        array $extraQuery = []
    ): array {
        $correlationId = (string) Str::uuid();

        $limit = max(1, $limit);
        $maxPages = max(1, $maxPages);

        $summary = [
            'resource' => $resource,
            'correlation_id' => $correlationId,
            'pages' => [],
        ];

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * $limit;

            $payload = $this->erp->getResourcePage(
                resource: $resource,
                offset: $offset,
                limit: $limit,
                correlationId: $correlationId,
                select: $select,
                filter: $filter,
                extraQuery: $extraQuery,
            );

            $path = $this->dumpWriter->writePage(
                resource: $resource,
                offset: $offset,
                limit: $limit,
                payload: $payload,
                meta: [
                    'page' => $page + 1,
                    'max_pages' => $maxPages,
                ]
            );

            $summary['pages'][] = [
                'page' => $page + 1,
                'offset' => $offset,
                'limit' => $limit,
                'path' => $path,
            ];
        }

        logger()->info('[ERP_RESOURCE_FETCH_DONE]', $summary);

        return $summary;
    }

    public function defaults(): array
    {
        return [
            'limit' => $this->defaultLimit,
            'max_pages' => $this->defaultMaxPages,
        ];
    }
}
