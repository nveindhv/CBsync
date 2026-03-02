<?php

namespace App\Jobs;

use App\Services\ERP\Fetch\ErpResourceFetchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchErpResourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $resource,
        public readonly int $limit,
        public readonly int $maxPages,
        public readonly ?string $select = null,
        public readonly ?string $filter = null,
        public readonly array $extraQuery = []
    ) {}

    public function handle(ErpResourceFetchService $service): void
    {
        $service->fetchAndDump(
            resource: $this->resource,
            limit: $this->limit,
            maxPages: $this->maxPages,
            select: $this->select,
            filter: $this->filter,
            extraQuery: $this->extraQuery,
        );
    }
}
