<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class KmsProbeErpWindow extends Command
{
    protected $signature = 'kms:probe:erp-window
        {--offset-from=88000 : ERP offset start}
        {--offset-to=98000 : ERP offset end (exclusive)}
        {--page-size=200 : ERP page size}
        {--select= : ERP select clause}
        {--execute : Actually call KMS createUpdate}
        {--only-classification= : Only process this classification, e.g. WEB}
        {--include-inactive : Also process inactive/deleted ERP rows}
        {--debug : Extra output}';

    protected $description = 'Scan an ERP offset window, decide practical ERP -> KMS upsert path, and log failures/skips.';

    public function handle(KmsClient $kms): int
    {
        $offsetFrom = max(0, (int) $this->option('offset-from'));
        $offsetTo = max($offsetFrom, (int) $this->option('offset-to'));
        $pageSize = max(1, (int) $this->option('page-size'));

        $selectOpt = $this->option('select');
        if (is_array($selectOpt)) {
            $selectOpt = implode(',', array_filter($selectOpt, fn ($v) => $v !== null && $v !== ''));
        }
        $select = trim((string) ($selectOpt ?? ''));
        if ($select === '') {
            $select = '*';
        }

        $execute = (bool) $this->option('execute');
        $onlyClassification = trim((string) ($this->option('only-classification') ?? ''));
        $includeInactive = (bool) $this->option('include-inactive');
        $debug = (bool) $this->option('debug');

        $erpBase = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpPath = trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = (string) env('ERP_ADMIN', '01');

        if ($erpBase === '' || $erpPath === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }

        $url = $erpBase . '/' . $erpPath . '/' . $erpAdmin . '/products';
        $runId = now()->format('Ymd_His');
        $dir = 'kms_probe_window';
        $stem = "window_{$offsetFrom}_{$offsetTo}_{$runId}";

        $summary = [
            'meta' => [
                'offset_from' => $offsetFrom,
                'offset_to' => $offsetTo,
                'page_size' => $pageSize,
                'select' => $select,
                'execute' => $execute,
                'only_classification' => $onlyClassification,
                'include_inactive' => $includeInactive,
                'generated_at' => now()->toIso8601String(),
            ],
            'counts' => [
                'pages' => 0,
                'rows_seen' => 0,
                'rows_with_article' => 0,
                'existing_kms' => 0,
                'missing_kms' => 0,
                'would_update' => 0,
                'would_create' => 0,
                'executed_ok' => 0,
                'executed_failed' => 0,
                'skipped_inactive' => 0,
                'skipped_classification' => 0,
                'skipped_missing_article' => 0,
            ],
            'failures' => [],
            'skips' => [],
            'samples' => [],
        ];

        $csv = [];
        $csv[] = [
            'offset', 'erp_id', 'article', 'ean', 'classification', 'erp_active', 'kms_visible',
            'decision', 'result', 'reason', 'name', 'brand', 'color', 'size', 'unit'
        ];

        for ($offset = $offsetFrom; $offset < $offsetTo; $offset += $pageSize) {
            $limit = min($pageSize, $offsetTo - $offset);

            $resp = Http::acceptJson()->get($url, [
                'offset' => $offset,
                'limit' => $limit,
                'select' => $select,
            ]);

            if (!$resp->successful()) {
                $summary['counts']['executed_failed']++;
                $summary['failures'][] = [
                    'offset' => $offset,
                    'stage' => 'erp_get',
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ];
                continue;
            }

            $rows = $resp->json();
            if (!is_array($rows)) {
                $rows = [];
            }

            $summary['counts']['pages']++;
            $summary['counts']['rows_seen'] += count($rows);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $erpId = $this->pick($row, ['id', 'ID']);
                $article = trim((string) $this->pick($row, ['articleNumber', 'article_number', 'article', 'code', 'number', 'sku']));
                $ean = trim((string) $this->pick($row, ['ean', 'eanCode', 'barcode']));
                $name = trim((string) $this->pick($row, ['name', 'description', 'title']));
                $unit = trim((string) $this->pick($row, ['unit', 'unitCode']));
                $brand = trim((string) $this->pick($row, ['brand', 'brandName']));
                $color = trim((string) $this->pick($row, ['color', 'colour']));
                $size = trim((string) $this->pick($row, ['size']));
                $classification = trim((string) $this->pick($row, ['productClassification', 'productClassificationCode', 'classification', 'classificationCode']));
                $price = $this->pick($row, ['price', 'salesPrice', 'salePrice']);
                $purchasePrice = $this->pick($row, ['purchasePrice', 'purchase_price', 'costPrice']);
                $supplierName = trim((string) $this->pick($row, ['supplierName', 'supplier_name', 'supplier', 'vendorName']));
                $erpActive = $this->normalizeActive($row);

                if ($article === '') {
                    $summary['counts']['skipped_missing_article']++;
                    $summary['skips'][] = ['offset' => $offset, 'erp_id' => $erpId, 'reason' => 'missing_article'];
                    continue;
                }

                $summary['counts']['rows_with_article']++;

                if (!$includeInactive && $erpActive === false) {
                    $summary['counts']['skipped_inactive']++;
                    $summary['skips'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'article' => $article,
                        'classification' => $classification,
                        'reason' => 'inactive_or_deleted',
                    ];
                    $csv[] = [$offset, $erpId, $article, $ean, $classification, '0', '', 'skip', '', 'inactive_or_deleted', $name, $brand, $color, $size, $unit];
                    continue;
                }

                if ($onlyClassification !== '' && strcasecmp($classification, $onlyClassification) !== 0) {
                    $summary['counts']['skipped_classification']++;
                    $summary['skips'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'article' => $article,
                        'classification' => $classification,
                        'reason' => 'classification_mismatch',
                    ];
                    $csv[] = [$offset, $erpId, $article, $ean, $classification, $erpActive ? '1' : '0', '', 'skip', '', 'classification_mismatch', $name, $brand, $color, $size, $unit];
                    continue;
                }

                $existing = $this->fetchOne($kms, $article, $ean);
                $kmsVisible = $existing !== null;

                if ($kmsVisible) {
                    $summary['counts']['existing_kms']++;
                    $summary['counts']['would_update']++;
                } else {
                    $summary['counts']['missing_kms']++;
                    $summary['counts']['would_create']++;
                }

                $decision = $kmsVisible ? 'update_without_type' : 'create_with_type_and_name';
                $payload = $kmsVisible
                    ? $this->buildUpdatePayload($article, $ean, $unit, $brand, $color, $size, $price, $purchasePrice, $supplierName, $name)
                    : $this->buildCreatePayload($article, $ean, $unit, $brand, $color, $size, $price, $purchasePrice, $supplierName, $name);

                if (count($summary['samples']) < 25) {
                    $summary['samples'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'article' => $article,
                        'classification' => $classification,
                        'decision' => $decision,
                        'payload' => $payload,
                    ];
                }

                $result = $execute ? 'pending' : 'dry_run';
                $reason = $kmsVisible ? 'visible_in_kms' : 'missing_in_kms';

                if ($debug) {
                    $this->line(sprintf('[%s] article=%s class=%s kms=%s decision=%s', $offset, $article, $classification !== '' ? $classification : '-', $kmsVisible ? 'yes' : 'no', $decision));
                    $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }

                if ($execute) {
                    try {
                        $raw = $kms->post('kms/product/createUpdate', $payload);
                        $after = $this->fetchOne($kms, $article, $ean);

                        if ($after !== null) {
                            $summary['counts']['executed_ok']++;
                            $result = 'ok';
                        } else {
                            $summary['counts']['executed_failed']++;
                            $result = 'not_visible_after_call';
                            $summary['failures'][] = [
                                'offset' => $offset,
                                'erp_id' => $erpId,
                                'article' => $article,
                                'classification' => $classification,
                                'decision' => $decision,
                                'payload' => $payload,
                                'raw_response' => $raw,
                            ];
                        }
                    } catch (\Throwable $e) {
                        $summary['counts']['executed_failed']++;
                        $result = 'exception';
                        $summary['failures'][] = [
                            'offset' => $offset,
                            'erp_id' => $erpId,
                            'article' => $article,
                            'classification' => $classification,
                            'decision' => $decision,
                            'payload' => $payload,
                            'exception' => $e->getMessage(),
                        ];
                    }
                }

                $csv[] = [$offset, $erpId, $article, $ean, $classification, $erpActive ? '1' : '0', $kmsVisible ? '1' : '0', $decision, $result, $reason, $name, $brand, $color, $size, $unit];
            }
        }

        Storage::disk('local')->put($dir . '/' . $stem . '.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put($dir . '/' . $stem . '.csv', $this->toCsv($csv));

        $this->info('JSON: storage/app/' . $dir . '/' . $stem . '.json');
        $this->info('CSV : storage/app/' . $dir . '/' . $stem . '.csv');
        $this->line('Summary: ' . json_encode($summary['counts'], JSON_UNESCAPED_SLASHES));
        $this->line('Interpretation: existing KMS => update without type, missing KMS => create with type + name + context.');
        $this->line('Productclassification is logged for every row and can be filtered with --only-classification=WEB');

        return self::SUCCESS;
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean): ?array
    {
        $raw = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ]);

        $items = $this->normalizeProducts($raw);
        foreach ($items as $item) {
            if ((string) Arr::get($item, 'articleNumber') === $article) {
                return $item;
            }
        }

        if ($ean !== null && $ean !== '') {
            $raw2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ]);
            $items2 = $this->normalizeProducts($raw2);
            foreach ($items2 as $item) {
                if ((string) Arr::get($item, 'articleNumber') === $article) {
                    return $item;
                }
                if ((string) Arr::get($item, 'ean') === $ean) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function normalizeProducts($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_values($raw), fn ($v) => is_array($v)));
    }

    private function buildUpdatePayload(string $article, string $ean, string $unit, string $brand, string $color, string $size, $price, $purchasePrice, string $supplierName, string $name): array
    {
        $product = [
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => $ean,
            'unit' => $unit,
            'brand' => $brand,
            'color' => $color,
            'size' => $size,
        ];

        if ($price !== null && $price !== '') {
            $product['price'] = is_numeric($price) ? (float) $price : $price;
        }
        if ($purchasePrice !== null && $purchasePrice !== '') {
            $product['purchase_price'] = is_numeric($purchasePrice) ? (float) $purchasePrice : $purchasePrice;
        }
        if ($supplierName !== '') {
            $product['supplier_name'] = $supplierName;
        }
        if ($name !== '') {
            $product['name'] = $name;
        }

        return ['products' => [$product]];
    }

    private function buildCreatePayload(string $article, string $ean, string $unit, string $brand, string $color, string $size, $price, $purchasePrice, string $supplierName, string $name): array
    {
        $typeNumber = ctype_digit($article) && strlen($article) >= 11 ? substr($article, 0, 11) : null;
        $typeName = $typeNumber ? 'FAMILY ' . $typeNumber : null;

        $product = [
            'article_number' => $article,
            'articleNumber' => $article,
            'ean' => $ean,
            'unit' => $unit,
            'brand' => $brand,
            'color' => $color,
            'size' => $size,
            'name' => $name,
        ];

        if ($price !== null && $price !== '') {
            $product['price'] = is_numeric($price) ? (float) $price : $price;
        }
        if ($purchasePrice !== null && $purchasePrice !== '') {
            $product['purchase_price'] = is_numeric($purchasePrice) ? (float) $purchasePrice : $purchasePrice;
        }
        if ($supplierName !== '') {
            $product['supplier_name'] = $supplierName;
        }
        if ($typeNumber !== null) {
            $product['type_number'] = $typeNumber;
            $product['typeNumber'] = $typeNumber;
        }
        if ($typeName !== null) {
            $product['type_name'] = $typeName;
            $product['typeName'] = $typeName;
        }

        return ['products' => [$product]];
    }

    private function pick(array $row, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    }

    private function normalizeActive(array $row): ?bool
    {
        if (array_key_exists('deleted', $row) && $this->toBool($row['deleted']) === true) {
            return false;
        }
        if (array_key_exists('active', $row)) {
            return $this->toBool($row['active']);
        }
        if (array_key_exists('inactive', $row)) {
            $inactive = $this->toBool($row['inactive']);
            return $inactive === null ? null : !$inactive;
        }
        return null;
    }

    private function toBool($value): ?bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value) || is_float($value)) return ((int) $value) !== 0;
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'y'], true)) return true;
            if (in_array($v, ['0', 'false', 'no', 'n'], true)) return false;
        }
        return null;
    }

    private function toCsv(array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        rewind($fp);
        return (string) stream_get_contents($fp);
    }
}
