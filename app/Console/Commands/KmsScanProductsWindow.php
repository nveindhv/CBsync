<?php

namespace App\Console\Commands;

use App\Services\KMS\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class KmsScanProductsWindow extends Command
{
    protected $signature = 'kms:scan:products-window
        {--offset=0 : Start offset}
        {--limit=200 : Page size}
        {--max-pages=50 : Maximum pages to fetch}
        {--article-number= : Optional exact article number filter}
        {--ean= : Optional exact EAN filter}
        {--dump-json : Save JSON dump}
        {--dump-csv : Save CSV dump}
        {--debug : Print payloads and raw counts}';

    protected $description = 'Fetch a KMS products window and dump raw rows for layer/parent analysis.';

    public function handle(KmsClient $kms): int
    {
        $offset = max(0, (int) $this->option('offset'));
        $limit = max(1, (int) $this->option('limit'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $articleNumber = trim((string) $this->option('article-number'));
        $ean = trim((string) $this->option('ean'));
        $dumpJson = (bool) $this->option('dump-json');
        $dumpCsv = (bool) $this->option('dump-csv');
        $debug = (bool) $this->option('debug');

        $rows = [];
        $pages = 0;
        $currentOffset = $offset;

        while ($pages < $maxPages) {
            $payload = [
                'offset' => $currentOffset,
                'limit' => $limit,
            ];

            if ($articleNumber !== '') {
                $payload['articleNumber'] = $articleNumber;
            }
            if ($ean !== '') {
                $payload['ean'] = $ean;
            }

            if ($debug) {
                $this->line('POST kms/product/getProducts');
                $this->line('PAYLOAD=' . json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            try {
                $raw = $kms->post('kms/product/getProducts', $payload);
            } catch (\Throwable $e) {
                $this->error('KMS request failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            $list = $this->normalizeList($raw);
            $count = count($list);
            $pages++;

            $this->info(sprintf('[KMS_PAGE_OK] offset=%d rows=%d', $currentOffset, $count));

            foreach ($list as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            if ($count < $limit || $articleNumber !== '' || $ean !== '') {
                break;
            }

            $currentOffset += $limit;
        }

        $stamp = now()->format('Ymd_His');
        $dir = 'kms_scan';
        Storage::makeDirectory($dir);
        $baseName = sprintf('products_window_%s', $stamp);

        if ($dumpJson) {
            Storage::put($dir . '/' . $baseName . '.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: ' . storage_path('app/' . $dir . '/' . $baseName . '.json'));
        }

        if ($dumpCsv) {
            $csvPath = $dir . '/' . $baseName . '.csv';
            $this->writeCsv($csvPath, $rows);
            $this->line('CSV : ' . storage_path('app/' . $csvPath));
        }

        $this->line('Summary: ' . json_encode([
            'pages' => $pages,
            'rows' => count($rows),
            'offset_start' => $offset,
            'limit' => $limit,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $looksAssoc = array_keys($raw) !== range(0, count($raw) - 1);
        if (!$looksAssoc) {
            return array_values(array_filter($raw, 'is_array'));
        }

        $allArrays = true;
        foreach ($raw as $value) {
            if (!is_array($value)) {
                $allArrays = false;
                break;
            }
        }

        if ($allArrays) {
            return array_values($raw);
        }

        if (isset($raw['products']) && is_array($raw['products'])) {
            return array_values(array_filter($raw['products'], 'is_array'));
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        $headers = [];
        foreach ($rows as $row) {
            $headers = array_values(array_unique(array_merge($headers, array_keys($row))));
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $line[] = (string) $value;
            }
            fputcsv($stream, $line);
        }
        rewind($stream);
        $contents = stream_get_contents($stream) ?: '';
        fclose($stream);
        Storage::put($path, $contents);
    }
}
