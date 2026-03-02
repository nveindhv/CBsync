<?php

namespace App\Console\Commands;

use App\Services\ERP\Schema\AuthorizationSchemaReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ErpSchemaCacheCommand extends Command
{
    protected $signature = 'erp:schema:cache {--schema=} {--force}';
    protected $description = 'Parse authorization schema and cache GET-enabled ERP resources to storage/app/erp_schema_cache.json';

    public function handle(AuthorizationSchemaReader $reader): int
    {
        try {
            $schemaPath = $this->option('schema') ?: (string) config('erp_gets.schema_path');
            $cachePath = (string) config('erp_gets.schema_cache_path');

            if (!$this->option('force') && Storage::disk('local')->exists($cachePath)) {
                $this->info("Cache already exists: storage/app/{$cachePath} (use --force to rebuild)");
                return self::SUCCESS;
            }

            $resources = $reader->readGetEnabledResources($schemaPath);

            Storage::disk('local')->put($cachePath, json_encode([
                'schema_path' => $schemaPath,
                'cached_at' => now()->toIso8601String(),
                'resources' => $resources,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->info('Cached GET-enabled resources: ' . count($resources));
            foreach ($resources as $r) {
                $this->line(' - ' . $r);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Schema cache failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
