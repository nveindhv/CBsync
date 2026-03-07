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
        $files = StoragePathResolver::globAll('erp_dump/prefix_matches_*.json');

        if (empty($files)) {
            $this->error('No ERP prefix_matches JSON files found.');
            return self::FAILURE;
        }

        $families = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
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
                if ($family === '') {
                    continue;
                }

                if (! isset($families[$family])) {
                    $families[$family] = [
                        'family' => $family,
                        'articles' => [],
                        'names' => [],
                        'external_hits' => 0,
                        'source_files' => [],
                    ];
                }

                $families[$family]['articles'][$productCode] = $productCode;

                $name = trim((string) ($row['description'] ?? $row['name'] ?? ''));
                if ($name !== '') {
                    $families[$family]['names'][$name] = ($families[$family]['names'][$name] ?? 0) + 1;
                }

                $external = trim((string) ($row['externalProductCode'] ?? ''));
                if ($external !== '') {
                    $families[$family]['external_hits']++;
                }

                $families[$family]['source_files'][basename($file)] = basename($file);
            }
        }

        foreach ($families as &$family) {
            $family['article_count'] = count($family['articles']);
            $family['articles'] = array_values($family['articles']);
            arsort($family['names']);
            $family['source_files'] = array_values($family['source_files']);
        }
        unset($family);

        ksort($families);

        $outDir = StoragePathResolver::ensurePrivateDir('erp_dump');
        $path = $outDir . DIRECTORY_SEPARATOR . 'combined_family_index.json';
        file_put_contents($path, json_encode(array_values($families), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Combined ERP families: ' . count($families));
        $this->line('JSON: ' . $path);

        if ($this->option('debug')) {
            foreach (array_slice(array_values($families), 0, 10) as $family) {
                $this->line(sprintf('%s => %d', $family['family'], $family['article_count']));
            }
        }

        return self::SUCCESS;
    }
}
