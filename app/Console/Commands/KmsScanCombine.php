<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsScanCombine extends Command
{
    protected $signature = 'kms:scan:combine {--dump-json} {--dump-csv} {--debug}';
    protected $description = 'Combine KMS products_window scans into one scan-first index.';

    public function handle(): int
    {
        $scanDir = StoragePathResolver::resolve('kms_scan');
        $files = glob($scanDir . DIRECTORY_SEPARATOR . 'products_window_*.json') ?: [];
        sort($files);

        if (empty($files)) {
            $this->error('No KMS products_window JSON files found.');
            return self::FAILURE;
        }

        $rows = [];
        $byArticle = [];
        $byPrefix = [];

        foreach ($files as $file) {
            $decoded = json_decode(file_get_contents($file), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $article = (string) ($row['articleNumber'] ?? '');
                if ($article === '') {
                    continue;
                }

                $rows[$article] = $row;
                $byArticle[$article] = $row;

                $prefix = substr($article, 0, 9);
                $byPrefix[$prefix] ??= [];
                $byPrefix[$prefix][] = $article;
            }
        }

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $jsonPath = $outDir . DIRECTORY_SEPARATOR . 'combined_products_scan.json';
        $indexPath = $outDir . DIRECTORY_SEPARATOR . 'combined_products_index.json';

        file_put_contents($jsonPath, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($indexPath, json_encode([
            'article_count' => count($byArticle),
            'prefix_count' => count($byPrefix),
            'by_article' => $byArticle,
            'by_prefix' => $byPrefix,
            'source_files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Combined articles: ' . count($byArticle));
        $this->line('JSON : ' . $jsonPath);
        $this->line('INDEX: ' . $indexPath);

        return self::SUCCESS;
    }
}
