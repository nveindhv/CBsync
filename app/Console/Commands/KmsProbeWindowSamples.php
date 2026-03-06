<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class KmsProbeWindowSamples extends Command
{
    protected $signature = 'kms:probe:window-samples
        {--csv= : Path to a kms_probe_window CSV file. Defaults to latest file in storage/app/kms_probe_window}
        {--mode=create : create|update|all}
        {--only-classification= : Optional exact classification filter, e.g. WEB}
        {--limit=20 : Max rows to export}
        {--per-brand=3 : Max rows per brand to keep the sample representative}
        {--write-commands : Also write ready-to-run Artisan commands to a txt file}
        {--debug : Show extra info}';

    protected $description = 'Build a small representative sample from an existing kms_probe_window CSV, so you can test create/update paths without re-running the ERP window scan.';

    public function handle(): int
    {
        $csv = $this->option('csv') ? (string) $this->option('csv') : $this->findLatestCsv();
        $mode = strtolower((string) ($this->option('mode') ?? 'create'));
        $limit = max(1, (int) ($this->option('limit') ?? 20));
        $perBrand = max(1, (int) ($this->option('per-brand') ?? 3));
        $onlyClassification = trim((string) ($this->option('only-classification') ?? ''));
        $debug = (bool) $this->option('debug');
        $writeCommands = (bool) $this->option('write-commands');

        if (!in_array($mode, ['create', 'update', 'all'], true)) {
            $this->error('Invalid --mode. Allowed: create|update|all');
            return self::FAILURE;
        }

        if (!$csv || !is_file($csv)) {
            $this->error('CSV not found: ' . ($csv ?: '[none found]'));
            $this->warn('Expected files like storage/app/kms_probe_window/window_*.csv');
            return self::FAILURE;
        }

        $rows = $this->readCsv($csv);
        if (empty($rows)) {
            $this->error('CSV is empty or unreadable: ' . $csv);
            return self::FAILURE;
        }

        if ($debug) {
            $this->line('CSV: ' . $csv);
            $this->line('Rows loaded: ' . count($rows));
        }

        $filtered = [];
        foreach ($rows as $row) {
            $article = trim($this->scalar($this->pick($row, ['article', 'articleNumber', 'article_number'])));
            if ($article === '') {
                continue;
            }

            $classification = trim($this->scalar($this->pick($row, ['classification', 'productClassification', 'productClassificationCode', 'classificationCode'])));
            if ($onlyClassification !== '' && strcasecmp($classification, $onlyClassification) !== 0) {
                continue;
            }

            $wouldCreate = $this->truthy($this->pick($row, ['would_create', 'wouldCreate', 'missing_kms', 'missingKms']));
            $wouldUpdate = $this->truthy($this->pick($row, ['would_update', 'wouldUpdate', 'existing_kms', 'existingKms']));

            if ($mode === 'create' && !$wouldCreate) {
                continue;
            }
            if ($mode === 'update' && !$wouldUpdate) {
                continue;
            }
            if ($mode === 'all' && !$wouldCreate && !$wouldUpdate) {
                continue;
            }

            $filtered[] = [
                'article' => $article,
                'ean' => trim($this->scalar($this->pick($row, ['ean']))),
                'name' => trim($this->scalar($this->pick($row, ['name', 'productName', 'description']))),
                'unit' => trim($this->scalar($this->pick($row, ['unit', 'unitCode']))),
                'brand' => trim($this->scalar($this->pick($row, ['brand', 'brandName']))),
                'color' => trim($this->scalar($this->pick($row, ['color', 'colour']))),
                'size' => trim($this->scalar($this->pick($row, ['size']))),
                'price' => $this->scalar($this->pick($row, ['price', 'salesPrice', 'salePrice'])),
                'purchase_price' => $this->scalar($this->pick($row, ['purchase_price', 'purchasePrice', 'costPrice'])),
                'supplier_name' => trim($this->scalar($this->pick($row, ['supplier_name', 'supplierName', 'supplier', 'vendorName']))),
                'classification' => $classification,
                'would_create' => $wouldCreate ? '1' : '0',
                'would_update' => $wouldUpdate ? '1' : '0',
                'source_offset' => $this->scalar($this->pick($row, ['source_offset', 'offset'])),
            ];
        }

        if (empty($filtered)) {
            $this->warn('No matching rows found after filtering.');
            return self::SUCCESS;
        }

        $sample = $this->buildRepresentativeSample($filtered, $limit, $perBrand);

        $stamp = date('Ymd_His');
        $baseDir = storage_path('app/kms_probe_window');
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0777, true);
        }

        $baseName = sprintf('sample_%s_%s', $mode, $stamp);
        $outCsv = $baseDir . DIRECTORY_SEPARATOR . $baseName . '.csv';
        $outJson = $baseDir . DIRECTORY_SEPARATOR . $baseName . '.json';
        $outTxt = $baseDir . DIRECTORY_SEPARATOR . $baseName . '_commands.txt';

        $this->writeCsv($outCsv, $sample);
        file_put_contents($outJson, json_encode([
            'meta' => [
                'source_csv' => $csv,
                'mode' => $mode,
                'only_classification' => $onlyClassification,
                'limit' => $limit,
                'per_brand' => $perBrand,
                'count' => count($sample),
                'created_at' => date(DATE_ATOM),
            ],
            'rows' => $sample,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($writeCommands) {
            $commands = [];
            foreach ($sample as $row) {
                $commands[] = $mode === 'update'
                    ? $this->buildUpsertFlowCommand($row)
                    : ($mode === 'create'
                        ? $this->buildCreatePathCommand($row)
                        : ($row['would_create'] === '1' ? $this->buildCreatePathCommand($row) : $this->buildUpsertFlowCommand($row)));
            }
            file_put_contents($outTxt, implode(PHP_EOL . PHP_EOL, $commands) . PHP_EOL);
        }

        $this->info('Source CSV: ' . $csv);
        $this->info('Sample rows: ' . count($sample));
        $this->line('CSV : ' . $outCsv);
        $this->line('JSON: ' . $outJson);
        if ($writeCommands) {
            $this->line('TXT : ' . $outTxt);
        }

        $this->newLine();
        $this->line('Preview:');
        foreach (array_slice($sample, 0, min(10, count($sample))) as $row) {
            $this->line(sprintf(
                '- [%s] %s | brand=%s | class=%s | ean=%s',
                $row['would_create'] === '1' ? 'CREATE' : 'UPDATE',
                $row['article'],
                $row['brand'] ?: '-',
                $row['classification'] ?: '-',
                $row['ean'] ?: '-'
            ));
        }

        $this->newLine();
        $this->comment('Suggested next command:');
        if ($writeCommands) {
            $this->line('type ' . str_replace('/', '\\', $outTxt));
        } else {
            $this->line('Run again with --write-commands to generate ready-to-run Artisan commands.');
        }

        return self::SUCCESS;
    }

    private function findLatestCsv(): ?string
    {
        $dir = storage_path('app/kms_probe_window');
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . DIRECTORY_SEPARATOR . 'window_*.csv') ?: [];
        if (empty($files)) {
            return null;
        }
        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0] ?? null;
    }

    private function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return [];
        }

        $header = null;
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if ($header === null) {
                $header = $data;
                continue;
            }
            if ($data === [null] || $data === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $data[$i] ?? null;
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Cannot write CSV: ' . $path);
        }
        if (empty($rows)) {
            fclose($fh);
            return;
        }

        fputcsv($fh, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
    }

    private function buildRepresentativeSample(array $rows, int $limit, int $perBrand): array
    {
        usort($rows, function (array $a, array $b): int {
            return [$a['classification'], $a['brand'], $a['article']] <=> [$b['classification'], $b['brand'], $b['article']];
        });

        $out = [];
        $brandCounts = [];

        foreach ($rows as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $brandKey = strtolower($row['brand'] ?: '[blank]');
            $brandCounts[$brandKey] = $brandCounts[$brandKey] ?? 0;
            if ($brandCounts[$brandKey] >= $perBrand) {
                continue;
            }
            $out[] = $row;
            $brandCounts[$brandKey]++;
        }

        if (count($out) < $limit) {
            $seen = [];
            foreach ($out as $r) {
                $seen[$r['article']] = true;
            }
            foreach ($rows as $row) {
                if (count($out) >= $limit) {
                    break;
                }
                if (isset($seen[$row['article']])) {
                    continue;
                }
                $out[] = $row;
                $seen[$row['article']] = true;
            }
        }

        return $out;
    }

    private function buildCreatePathCommand(array $row): string
    {
        return sprintf(
            'php artisan kms:reverse:create-path %s --ean=%s --unit=%s --brand=%s --color=%s --size=%s --price=%s --purchase-price=%s --supplier-name=%s --name=%s --debug',
            $this->q($row['article']),
            $this->q($row['ean']),
            $this->q($row['unit']),
            $this->q($row['brand']),
            $this->q($row['color']),
            $this->q($row['size']),
            $this->q($this->numOrDefault($row['price'], '0')),
            $this->q($this->numOrDefault($row['purchase_price'], '0')),
            $this->q($row['supplier_name']),
            $this->q($row['name'] ?: $row['article'])
        );
    }

    private function buildUpsertFlowCommand(array $row): string
    {
        $namePart = trim($row['name']) !== '' ? ' --name=' . $this->q($row['name']) : '';

        return sprintf(
            'php artisan kms:probe:upsert-flow %s --ean=%s --unit=%s --brand=%s --color=%s --size=%s --price=%s --purchase-price=%s --supplier-name=%s%s --debug',
            $this->q($row['article']),
            $this->q($row['ean']),
            $this->q($row['unit']),
            $this->q($row['brand']),
            $this->q($row['color']),
            $this->q($row['size']),
            $this->q($this->numOrDefault($row['price'], '0')),
            $this->q($this->numOrDefault($row['purchase_price'], '0')),
            $this->q($row['supplier_name']),
            $namePart
        );
    }

    private function q(?string $value): string
    {
        $value = (string) ($value ?? '');
        return '"' . str_replace('"', '\\"', $value) . '"';
    }

    private function numOrDefault($value, string $default): string
    {
        $v = trim($this->scalar($value));
        return $v === '' ? $default : $v;
    }

    private function truthy($value): bool
    {
        $v = strtolower(trim($this->scalar($value)));
        return in_array($v, ['1', 'true', 'yes', 'y'], true);
    }

    private function pick(array $row, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return null;
    }

    private function scalar($value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }
}
