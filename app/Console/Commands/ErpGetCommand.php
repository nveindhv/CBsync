<?php

namespace App\Console\Commands;

use App\Services\ERP\ErpApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ErpGetCommand extends Command
{
    protected $signature = 'erp:get {endpoint : ERP endpoint, e.g. products or products/123}
                            {--limit=200 : Page size}
                            {--offset=0 : Start offset}
                            {--max-pages=1 : Max pages to fetch}
                            {--no-dump : Do not write response JSON to storage}
                            {--raw : Print raw body instead of JSON pretty}';

    protected $description = 'Run ERP GET for a configured resource and dump responses to storage/app';

    public function handle(ErpApiClient $client): int
    {
        $endpoint = (string) $this->argument('endpoint');

        $limit = (int) $this->option('limit');
        if ($limit <= 0) $limit = 200;

        $offset = (int) $this->option('offset');
        if ($offset < 0) $offset = 0;

        $maxPages = (int) $this->option('max-pages');
        if ($maxPages <= 0) $maxPages = 1;

        $doDump = ! (bool) $this->option('no-dump');
        $raw = (bool) $this->option('raw');

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $pageOffset = $offset + ($page * $limit);

                $query = [
                    'offset' => $pageOffset,
                    'limit' => $limit,
                ];

                $res = $client->get($endpoint, $query);

                $status = $res->status();
                $this->info("GET {$endpoint} (offset={$pageOffset}, limit={$limit}) -> HTTP {$status}");

                if (! $res->successful()) {
                    $body = $res->body();
                    $this->error('Request failed. Body:');
                    $this->line($body);
                    return self::FAILURE;
                }

                $body = $res->body();

                if ($raw) {
                    $this->line($body);
                } else {
                    // Attempt to pretty-print JSON when possible
                    $decoded = $res->json();
                    if ($decoded !== null) {
                        $this->line(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    } else {
                        $this->line($body);
                    }
                }

                if ($doDump) {
                    $dumpDir = (string) config('erp_gets.dump_dir', 'erp_dump');
                    $safeEndpoint = preg_replace('/[^a-zA-Z0-9_\-\/]+/', '_', $endpoint) ?: 'endpoint';
                    $safeEndpoint = str_replace('/', DIRECTORY_SEPARATOR, $safeEndpoint);

                    $pathDir = $dumpDir . DIRECTORY_SEPARATOR . $safeEndpoint;
                    $file = 'offset_' . $pageOffset . '_limit_' . $limit . '.json';

                    Storage::disk('local')->put($pathDir . DIRECTORY_SEPARATOR . $file, $body);
                    $this->comment('Dumped to storage/app/' . $pathDir . '/' . $file);
                }

                // Stop early if it looks like last page.
                $decoded = $res->json();
                if (is_array($decoded)) {
                    // Common ERP patterns: items/data/results arrays.
                    $count = null;
                    foreach (['items', 'data', 'results', 'value'] as $k) {
                        if (isset($decoded[$k]) && is_array($decoded[$k])) {
                            $count = count($decoded[$k]);
                            break;
                        }
                    }
                    if ($count !== null && $count < $limit) {
                        $this->comment('Looks like last page (returned < limit). Stopping.');
                        break;
                    }
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('ERP GET failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
