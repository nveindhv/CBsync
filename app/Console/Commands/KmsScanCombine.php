<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsScanCombine extends Command
{
    protected $signature = 'kms:scan:combine {--dump-json} {--dump-csv} {--debug}';
    protected $description = 'Combine KMS products_window scans into a light family index without exhausting memory.';

    public function handle(): int
    {
        $files = StoragePathResolver::globAll('kms_scan/products_window_*.json');

        if (empty($files)) {
            $this->error('No KMS products_window JSON files found.');
            return self::FAILURE;
        }

        $families = [];
        $articleCount = 0;

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $article = trim((string) ($row['articleNumber'] ?? ''));
                if ($article === '') {
                    continue;
                }

                $family = substr($article, 0, 9);
                if ($family === '') {
                    continue;
                }

                if (! isset($families[$family])) {
                    $families[$family] = [
                        'family' => $family,
                        'articles' => [],
                        'sample' => [
                            'articleNumber' => $article,
                            'name' => (string) ($row['name'] ?? ''),
                            'brand' => (string) ($row['brand'] ?? ''),
                            'unit' => (string) ($row['unit'] ?? 'STK'),
                            'price' => $row['price'] ?? null,
                            'color' => $row['color'] ?? null,
                            'size' => $row['size'] ?? null,
                            'ean' => $row['ean'] ?? null,
                        ],
                        'source_files' => [],
                    ];
                }

                $families[$family]['articles'][$article] = $article;
                $families[$family]['source_files'][basename($file)] = basename($file);
                $articleCount++;
            }

            unset($decoded);
        }

        foreach ($families as &$family) {
            $family['article_count'] = count($family['articles']);
            $family['articles'] = array_values($family['articles']);
            sort($family['articles']);
            $family['source_files'] = array_values($family['source_files']);
        }
        unset($family);

        ksort($families);

        $payload = [
            'family_count' => count($families),
            'article_count_seen' => $articleCount,
            'source_files' => array_values($files),
            'families' => $families,
        ];

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $jsonPath = $outDir . DIRECTORY_SEPARATOR . 'combined_products_index.json';
        file_put_contents($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Combined KMS families: ' . count($families));
        $this->line('JSON : ' . $jsonPath);

        if ($this->option('dump-csv')) {
            $csvPath = $outDir . DIRECTORY_SEPARATOR . 'combined_products_index.csv';
            $fh = fopen($csvPath, 'wb');
            fputcsv($fh, ['family', 'article_count', 'sample_article', 'name', 'brand', 'unit']);
            foreach ($families as $family) {
                fputcsv($fh, [
                    $family['family'],
                    $family['article_count'],
                    $family['sample']['articleNumber'] ?? '',
                    $family['sample']['name'] ?? '',
                    $family['sample']['brand'] ?? '',
                    $family['sample']['unit'] ?? '',
                ]);
            }
            fclose($fh);
            $this->line('CSV  : ' . $csvPath);
        }

        if ($this->option('debug')) {
            foreach (array_slice(array_values($families), 0, 15) as $family) {
                $this->line(sprintf('%s => %d', $family['family'], $family['article_count']));
            }
        }

        return self::SUCCESS;
    }
}
