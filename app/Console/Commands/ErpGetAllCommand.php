<?php

namespace App\Console\Commands;

use App\Jobs\FetchErpResourceJob;
use App\Services\ERP\Fetch\ErpResourceFetchService;
use Illuminate\Console\Command;
use Throwable;

class ErpGetAllCommand extends Command
{
    protected $signature = 'erp:get:all
        {--limit= : Page size (default from config)}
        {--max-pages= : Pages per resource (default from config)}
        {--only= : Comma-separated resources to run (subset)}
        {--except= : Comma-separated resources to skip}
        {--sync : Run inline (no queue), mainly for debugging}';

    protected $description = 'Dispatch jobs for all configured ERP GET resources (dispatcher only; no schema file)';

    public function handle(ErpResourceFetchService $service): int
    {
        try {
            $resources = (array) config('erp_gets.resources', []);

            $only = trim((string) ($this->option('only') ?? ''));
            if ($only !== '') {
                $subset = array_values(array_filter(array_map('trim', explode(',', $only))));
                $resources = array_values(array_intersect($resources, $subset));
            }

            $except = trim((string) ($this->option('except') ?? ''));
            if ($except !== '') {
                $skip = array_values(array_filter(array_map('trim', explode(',', $except))));
                $resources = array_values(array_diff($resources, $skip));
            }

            $defaults = $service->defaults();
            $limit = (int) ($this->option('limit') ?: $defaults['limit']);
            $maxPages = (int) ($this->option('max-pages') ?: $defaults['max_pages']);

            $this->info('Resources to fetch: ' . count($resources));

            $overrides = (array) (config('erp_gets.resources_overrides') ?? []);

            foreach ($resources as $r) {
                $select = null;
                $filter = null;
                $extraQuery = [];

                if (isset($overrides[$r]) && is_array($overrides[$r])) {
                    $ov = $overrides[$r];
                    $select = isset($ov['select']) ? (string) $ov['select'] : null;
                    $filter = isset($ov['filter']) ? (string) $ov['filter'] : null;
                    $extraQuery = isset($ov['extra_query']) && is_array($ov['extra_query']) ? $ov['extra_query'] : [];
                }

                if ($this->option('sync')) {
                    $service->fetchAndDump(
                        resource: $r,
                        limit: $limit,
                        maxPages: $maxPages,
                        select: $select,
                        filter: $filter,
                        extraQuery: $extraQuery
                    );
                    $this->line('Fetched: ' . $r);
                    continue;
                }

                FetchErpResourceJob::dispatch(
                    resource: $r,
                    limit: $limit,
                    maxPages: $maxPages,
                    select: $select,
                    filter: $filter,
                    extraQuery: $extraQuery
                );

                $this->line('Queued: ' . $r);
            }

            $this->info('Done. ' . ($this->option('sync') ? 'Fetched all inline.' : 'All jobs queued.'));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Dispatch failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
