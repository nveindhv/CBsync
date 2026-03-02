<?php

namespace App\Console\Commands;

use App\Jobs\FetchErpResourceJob;
use App\Services\ERP\Fetch\ErpResourceFetchService;
use Illuminate\Console\Command;
use Throwable;

class ErpGetResourceCommand extends Command
{
    protected $signature = 'erp:get {resource : ERP resource (e.g. addressTypes)}
        {--limit= : Page size (default from config)}
        {--max-pages= : How many pages to fetch (default from config)}
        {--select= : ERP select query param}
        {--filter= : ERP filter query param}
        {--query= : Extra query string like "foo=1&bar=2"}
        {--any : Allow resources not in the configured list}
        {--sync : Run inline (no queue), useful for debugging}';

    protected $description = 'Fetch one ERP resource (GET) and dump JSON pages to storage/app/erp_dump';

    public function handle(ErpResourceFetchService $service): int
    {
        try {
            $resource = (string) $this->argument('resource');

            $allowed = (array) config('erp_gets.resources', []);
            if (!$this->option('any') && !in_array($resource, $allowed, true)) {
                $this->error("Resource '{$resource}' is not in the configured GET list.");
                $this->line('Run: php artisan erp:resources');
                $this->line('Or pass --any to force it anyway.');
                return self::FAILURE;
            }

            $defaults = $service->defaults();

            $limit = (int) ($this->option('limit') ?: $defaults['limit']);
            $maxPages = (int) ($this->option('max-pages') ?: $defaults['max_pages']);

            $select = $this->option('select');
            $filter = $this->option('filter');

            $extraQuery = [];
            $queryStr = (string) ($this->option('query') ?: '');
            if (trim($queryStr) !== '') {
                parse_str($queryStr, $extraQuery);
                if (!is_array($extraQuery)) {
                    $extraQuery = [];
                }
            }

            // Optional per-resource defaults
            $overrides = (array) (config('erp_gets.resources_overrides') ?? []);
            if (isset($overrides[$resource]) && is_array($overrides[$resource])) {
                $ov = $overrides[$resource];
                if (($select === null || trim((string) $select) === '') && isset($ov['select'])) {
                    $select = (string) $ov['select'];
                }
                if (($filter === null || trim((string) $filter) === '') && isset($ov['filter'])) {
                    $filter = (string) $ov['filter'];
                }
                if (isset($ov['extra_query']) && is_array($ov['extra_query'])) {
                    $extraQuery = array_merge($ov['extra_query'], $extraQuery);
                }
            }

            if ($this->option('sync')) {
                $summary = $service->fetchAndDump(
                    resource: $resource,
                    limit: $limit,
                    maxPages: $maxPages,
                    select: $select,
                    filter: $filter,
                    extraQuery: $extraQuery
                );

                $this->info('Done. Dumped pages: ' . count($summary['pages']));
                foreach ($summary['pages'] as $p) {
                    $this->line(' - ' . $p['path']);
                }

                return self::SUCCESS;
            }

            FetchErpResourceJob::dispatch(
                resource: $resource,
                limit: $limit,
                maxPages: $maxPages,
                select: is_null($select) ? null : (string) $select,
                filter: is_null($filter) ? null : (string) $filter,
                extraQuery: $extraQuery
            );

            $this->info("Queued job: {$resource} (limit={$limit}, maxPages={$maxPages})");
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Fetch failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
