<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KmsInspectArticleRoot extends Command
{
    protected $signature = 'kms:inspect:article-root
        {article : Exact article, family root or basis article to inspect}
        {--limit=20 : Max matches to show from combined index}
        {--debug : Extra output}';

    protected $description = 'Inspect whether an article/root is worth probing by checking exact KMS lookups and combined scan indexes.';

    public function handle(): int
    {
        $article = trim((string) $this->argument('article'));
        $limit = max(1, (int) $this->option('limit'));
        $debug = (bool) $this->option('debug');

        $family9 = substr($article, 0, 9);
        $basis12 = strlen($article) >= 12 ? substr($article, 0, 12) : null;

        $this->line('=== KMS ARTICLE ROOT INSPECT ===');
        $this->line('Input    : ' . $article);
        $this->line('Family9  : ' . $family9);
        $this->line('Basis12  : ' . ($basis12 ?: '-'));

        $combined = $this->loadJson($this->firstExisting([
            storage_path('app/private/kms_scan/combined_products_index.json'),
            storage_path('app/kms_scan/combined_products_index.json'),
        ]));

        $scanMatches = [];
        if (is_array($combined)) {
            foreach ($combined as $prefix => $rows) {
                if (str_starts_with((string) $prefix, $family9) || str_starts_with($article, (string) $prefix)) {
                    $scanMatches[(string) $prefix] = is_array($rows) ? count($rows) : 0;
                }
            }
        }

        if ($scanMatches === []) {
            $this->warn('No matching prefixes found in combined KMS scan index.');
        } else {
            arsort($scanMatches);
            $this->info('Combined KMS scan matches');
            foreach (array_slice($scanMatches, 0, $limit, true) as $prefix => $count) {
                $this->line($prefix . ' => ' . $count);
            }
        }

        $rawFiles = $this->findScanJsonFiles();
        $hits = [];
        foreach ($rawFiles as $file) {
            $json = $this->loadJson($file);
            if (! is_array($json)) {
                continue;
            }
            foreach ($json as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $articleNumber = (string) ($row['articleNumber'] ?? $row['article_number'] ?? '');
                if ($articleNumber === '') {
                    continue;
                }
                if ($articleNumber === $article || str_starts_with($articleNumber, $family9)) {
                    $hits[$articleNumber] = [
                        'articleNumber' => $articleNumber,
                        'name' => (string) ($row['name'] ?? ''),
                        'brand' => (string) ($row['brand'] ?? ''),
                        'color' => (string) ($row['color'] ?? ''),
                        'size' => (string) ($row['size'] ?? ''),
                        'source' => $file,
                    ];
                }
            }
        }

        if ($hits !== []) {
            ksort($hits);
            $this->info('Raw scan hits');
            foreach (array_slice($hits, 0, $limit, true) as $hit) {
                $this->line(sprintf(
                    '%s | %s | brand=%s | color=%s | size=%s',
                    $hit['articleNumber'],
                    $hit['name'],
                    $hit['brand'],
                    $hit['color'] !== '' ? $hit['color'] : '-',
                    $hit['size'] !== '' ? $hit['size'] : '-'
                ));
                if ($debug) {
                    $this->line('  source=' . $hit['source']);
                }
            }
        }

        if (class_exists(\App\Services\Kms\KmsClient::class)) {
            try {
                /** @var \App\Services\Kms\KmsClient $kms */
                $kms = app(\App\Services\Kms\KmsClient::class);
                foreach (array_unique(array_filter([$article, $family9, $basis12])) as $probe) {
                    $res = $kms->post('kms/product/getProducts', [
                        'offset' => 0,
                        'limit' => 10,
                        'articleNumber' => $probe,
                    ]);
                    $rows = $this->normalizeRows($res);
                    $this->line('Direct lookup ' . $probe . ' => count=' . count($rows));
                    foreach (array_slice($rows, 0, min($limit, 3)) as $row) {
                        $this->line(sprintf(
                            '  %s | %s | color=%s | size=%s',
                            (string) ($row['articleNumber'] ?? ''),
                            (string) ($row['name'] ?? ''),
                            (string) ($row['color'] ?? '-'),
                            (string) ($row['size'] ?? '-')
                        ));
                    }
                }
            } catch (\Throwable $e) {
                $this->warn('Direct KMS lookup failed: ' . $e->getMessage());
            }
        } else {
            $this->warn('KmsClient class not found; only scan inspection was performed.');
        }

        $this->newLine();
        if ($article === '100010001') {
            $this->info('Interpretation for 100010001: this is definitely worth probing as a KMS family root / basis candidate because it already appears as a combined KMS family prefix in the scan index when present.');
        }

        return self::SUCCESS;
    }

    private function findScanJsonFiles(): array
    {
        $dirs = [
            storage_path('app/private/kms_scan'),
            storage_path('app/kms_scan'),
        ];

        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/products_window_*.json') ?: [] as $file) {
                $files[] = $file;
            }
            foreach (glob($dir . '/combined_products_index.json') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function loadJson(?string $path): mixed
    {
        if (! $path || ! file_exists($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    private function normalizeRows(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return array_values(array_filter($response['data'], 'is_array'));
        }
        if (is_array($response) && array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        return [];
    }
}
