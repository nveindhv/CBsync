<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

abstract class KmsBaseGetCommand extends Command
{
    protected function callPaged(KmsClient $client, string $endpoint, array $payload, string $dumpSubdir): int
    {
        $limit = (int) ($this->option('limit') ?? 50);
        $offset = (int) ($this->option('offset') ?? 0);
        $maxPages = (int) ($this->option('max-pages') ?? 1);

        for ($page = 0; $page < $maxPages; $page++) {
            $currentOffset = $offset + ($page * $limit);

            $pagePayload = array_merge($payload, [
                'limit' => $limit,
                'offset' => $currentOffset,
            ]);

            $this->info(sprintf('POST %s (offset=%d, limit=%d)', $endpoint, $currentOffset, $limit));

            try {
                $data = $client->post($endpoint, $pagePayload);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return Command::FAILURE;
            }

            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if (config('kms.dump_enabled')) {
                $dir = trim(config('kms.dump_dir'), '/');
                $path = "{$dir}/{$dumpSubdir}/offset_{$currentOffset}_limit_{$limit}.json";
                Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("Dumped to storage/app/{$path}");
            }

            // Stop early if result set is smaller than limit (common pattern)
            if (is_array($data) && count($data) < $limit) {
                break;
            }

            // If wrapped response: { data: [...] }
            if (is_array($data) && array_key_exists('data', $data) && is_array($data['data']) && count($data['data']) < $limit) {
                break;
            }
        }

        return Command::SUCCESS;
    }
}
