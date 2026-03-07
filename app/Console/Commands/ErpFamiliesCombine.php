<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class ErpFamiliesCombine extends Command
{
    protected $signature = 'erp:families:combine {--dump-json} {--debug}';
    protected $description = 'Combine ERP prefix_matches dumps into family-level buckets.';

    public function handle(): int
    {
        $dir = StoragePathResolver::resolve('erp_dump');
        $files = glob($dir . DIRECTORY_SEPARATOR . 'prefix_matches_*.json') ?: [];
        sort($files);

        if (empty($files)) {
            $this->error('No ERP prefix_matches JSON files found.');
            return self::FAILURE;
        }

        $families = [];

        foreach ($files as $file) {
            $decoded = json_decode(file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $productCode = (string) ($row['productCode'] ?? '');
                if ($productCode === '') {
                    continue;
                }
                $family = substr($productCode, 0, 9);
                $families[$family] ??= [
                    'family' => $family,
                    'articles' => [],
                    'names' => [],
                    'external_hits' => 0,
                    'source_files' => [],
                ];
                $families[$family]['articles'][$productCode] = $productCode;
                $name = (string) ($row['description'] ?? $row['name'] ?? '');
                if ($name !== '') {
                    $families[$family]['names'][$name] = ($families[$family]['names'][$name] ?? 0) + 1;
                }
                if (($row['externalProductCode'] ?? null) !== null && (string) $row['externalProductCode'] !== '') {
                    $families[$family]['external_hits']++;
                }
                $families[$family]['source_files'][$file] = basename($file);
            }
        }

        foreach ($families as &$family) {
            $family['article_count'] = count($family['articles']);
            $family['articles'] = array_values($family['articles']);
            arsort($family['names']);
            $family['source_files'] = array_values($family['source_files']);
        }
        unset($family);

        $outDir = StoragePathResolver::ensurePrivateDir('erp_dump');
        $path = $outDir . DIRECTORY_SEPARATOR . 'combined_family_index.json';
        file_put_contents($path, json_encode(array_values($families), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Combined ERP families: ' . count($families));
        $this->line('JSON: ' . $path);

        return self::SUCCESS;
    }
}
