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
        {--offset-from=0 : Start offset in ERP}
        {--offset-to=1000 : End offset in ERP (exclusive)}
        {--page-size=200 : ERP page size}
        {--select=* : ERP select clause}
        {--only-classification= : Only include this classification, e.g. WEB}
        {--include-inactive : Include inactive/deleted ERP rows}
        {--execute : Actually call KMS createUpdate instead of dry-run}
        {--insecure : Disable SSL verification for ERP HTTP calls}
        {--timeout=120 : Total ERP request timeout in seconds}
        {--connect-timeout=20 : ERP connect timeout in seconds}
        {--retries=1 : Retry count for ERP HTTP calls}
        {--retry-sleep=500 : Retry sleep in ms}
        {--debug : Verbose output}';

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
        $insecure = (bool) $this->option('insecure');
        $timeout = max(5, (int) $this->option('timeout'));
        $connectTimeout = max(2, (int) $this->option('connect-timeout'));
        $retries = max(0, (int) $this->option('retries'));
        $retrySleep = max(0, (int) $this->option('retry-sleep'));
        $debug = (bool) $this->option('debug');

        $erpBase = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpPath = trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = (string) env('ERP_ADMIN', '01');
        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBase === '' || $erpPath === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }

        if ($erpUser === '' || $erpPass === '') {
            $this->error('Missing ERP_USER or ERP_PASS in .env');
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
                'insecure' => $insecure,
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
                'retries' => $retries,
                'retry_sleep' => $retrySleep,
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
            'offset', 'erp_id', 'article', 'article_source', 'ean', 'classification', 'classification_raw', 'erp_active', 'kms_visible',
            'decision', 'result', 'reason', 'name', 'brand', 'color', 'size', 'unit',
        ];

        for ($offset = $offsetFrom; $offset < $offsetTo; $offset += $pageSize) {
            $limit = min($pageSize, $offsetTo - $offset);

            try {
                $resp = Http::withBasicAuth($erpUser, $erpPass)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->retry($retries, $retrySleep, throw: false)
                    ->withOptions(['verify' => !$insecure])
                    ->get($url, [
                        'offset' => $offset,
                        'limit' => $limit,
                        'select' => $select,
                    ]);
            } catch (\Throwable $e) {
                $summary['counts']['executed_failed']++;
                $summary['failures'][] = [
                    'offset' => $offset,
                    'stage' => 'erp_get_exception',
                    'message' => $e->getMessage(),
                    'verify_ssl' => !$insecure,
                ];
                if ($debug) {
                    $this->warn(sprintf('[ERP_GET_EXCEPTION] offset=%d verify_ssl=%s msg=%s', $offset, !$insecure ? 'true' : 'false', $e->getMessage()));
                }
                continue;
            }

            if (!$resp->successful()) {
                $summary['counts']['executed_failed']++;
                $summary['failures'][] = [
                    'offset' => $offset,
                    'stage' => 'erp_get',
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'verify_ssl' => !$insecure,
                ];
                if ($debug) {
                    $this->warn(sprintf('[ERP_GET_FAILED] offset=%d status=%d verify_ssl=%s', $offset, $resp->status(), !$insecure ? 'true' : 'false'));
                }
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

                $erpId = $this->toFlatString($this->pick($row, ['erp_id', 'id', 'ID', 'identifier', 'sku']));
                [$article, $articleSource] = $this->extractArticle($row);
                $ean = $this->toFlatString($this->pick($row, ['ean', 'eanCode', 'ean_code', 'barcode']));
                $name = $this->toFlatString($this->pick($row, ['name', 'description', 'title', 'productName']));
                $unit = $this->toFlatString($this->pick($row, ['unit', 'unitCode', 'salesUnit']));
                $brand = $this->toFlatString($this->pick($row, ['brand', 'brandName']));
                $color = $this->toFlatString($this->pick($row, ['color', 'colour']));
                $size = $this->toFlatString($this->pick($row, ['size']));
                $classificationRaw = $this->pick($row, ['productClassification', 'productClassificationCode', 'classification', 'classificationCode']);
                $classification = $this->normalizeClassification($classificationRaw);
                $erpActive = $this->normalizeActive($row);

                if (!$includeInactive && $erpActive === false) {
                    $summary['counts']['skipped_inactive']++;
                    $summary['skips'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'reason' => 'inactive',
                    ];
                    continue;
                }

                if ($onlyClassification !== '' && strcasecmp($classification, $onlyClassification) !== 0) {
                    $summary['counts']['skipped_classification']++;
                    $summary['skips'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'classification' => $classification,
                        'reason' => 'classification_mismatch',
                    ];
                    continue;
                }

                if ($article === '') {
                    $summary['counts']['skipped_missing_article']++;
                    $summary['skips'][] = [
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'reason' => 'missing_article',
                        'candidate_keys' => implode(',', array_keys($row)),
                    ];

                    $csv[] = [
                        $offset,
                        $erpId,
                        '',
                        '',
                        $ean,
                        $classification,
                        $this->safeJson($classificationRaw),
                        $this->boolString($erpActive),
                        '',
                        '',
                        '',
                        'missing_article',
                        $name,
                        $brand,
                        $color,
                        $size,
                        $unit,
                    ];

                    if ($debug && count($summary['samples']) < 25) {
                        $summary['samples'][] = [
                            'kind' => 'missing_article',
                            'offset' => $offset,
                            'erp_id' => $erpId,
                            'article_source' => $articleSource,
                            'keys' => array_keys($row),
                            'row_excerpt' => $this->excerptRow($row),
                        ];
                    }
                    continue;
                }

                $summary['counts']['rows_with_article']++;

                $visible = $this->fetchOneKms($kms, $article, $ean);
                $kmsVisible = $visible !== null;
                $decision = $kmsVisible ? 'update_without_type' : 'create_with_type_name_context';
                if ($kmsVisible) {
                    $summary['counts']['existing_kms']++;
                    $summary['counts']['would_update']++;
                } else {
                    $summary['counts']['missing_kms']++;
                    $summary['counts']['would_create']++;
                }

                $result = $execute ? 'not_implemented_in_patch' : 'dry_run';

                $csv[] = [
                    $offset,
                    $erpId,
                    $article,
                    $articleSource,
                    $ean,
                    $classification,
                    $this->safeJson($classificationRaw),
                    $this->boolString($erpActive),
                    $kmsVisible ? 'yes' : 'no',
                    $decision,
                    $result,
                    '',
                    $name,
                    $brand,
                    $color,
                    $size,
                    $unit,
                ];

                if ($debug && count($summary['samples']) < 25) {
                    $summary['samples'][] = [
                        'kind' => 'row',
                        'offset' => $offset,
                        'erp_id' => $erpId,
                        'article' => $article,
                        'article_source' => $articleSource,
                        'classification' => $classification,
                        'kms_visible' => $kmsVisible,
                        'row_excerpt' => $this->excerptRow($row),
                    ];
                }
            }
        }

        Storage::makeDirectory($dir);
        $jsonPath = $dir . '/' . $stem . '.json';
        $csvPath = $dir . '/' . $stem . '.csv';

        Storage::put($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $csvText = '';
        foreach ($csv as $line) {
            $escaped = array_map(function ($v) {
                $v = (string) $v;
                $v = str_replace('"', '""', $v);
                return '"' . $v . '"';
            }, $line);
            $csvText .= implode(',', $escaped) . "\n";
        }
        Storage::put($csvPath, $csvText);

        $this->line('JSON: storage/app/' . $jsonPath);
        $this->line('CSV : storage/app/' . $csvPath);
        $this->line('Summary: ' . json_encode($summary['counts'], JSON_UNESCAPED_SLASHES));
        $this->line('Interpretation: existing KMS => update without type, missing KMS => create with type + name + context.');
        $this->line('Productclassification is logged for every row and can be filtered with --only-classification=WEB');
        $this->line('ERP SSL verify: ' . ($insecure ? 'OFF' : 'ON'));

        return self::SUCCESS;
    }

    private function fetchOneKms(KmsClient $kms, string $article, string $ean = ''): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ]);

        $items = $this->normalizeList($res);
        foreach ($items as $item) {
            if ((string) Arr::get($item, 'articleNumber') === $article) {
                return $item;
            }
        }

        if ($ean !== '') {
            $res = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ]);
            $items = $this->normalizeList($res);
            foreach ($items as $item) {
                if ((string) Arr::get($item, 'articleNumber') === $article || (string) Arr::get($item, 'ean') === $ean) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function normalizeList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_values($value), 'is_array'));
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

    private function extractArticle(array $row): array
    {
        foreach (['articleNumber', 'article_number', 'article', 'sku', 'skuCode', 'productCode', 'code', 'itemCode', 'item_code', 'number'] as $key) {
            $raw = $this->pick($row, [$key]);
            $article = $this->normalizeArticleCandidate($raw);
            if ($article !== '') {
                return [$article, $key];
            }
        }

        foreach (['erp_id', 'id', 'identifier'] as $key) {
            $raw = $this->pick($row, [$key]);
            $article = $this->normalizeArticleFromComposite($raw);
            if ($article !== '') {
                return [$article, $key . '_composite'];
            }
        }

        return ['', ''];
    }

    private function normalizeArticleCandidate($raw): string
    {
        $s = $this->toFlatString($raw);
        if ($s === '') {
            return '';
        }
        if (str_contains($s, ',')) {
            $s = trim(explode(',', $s, 2)[0]);
        }
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        return preg_match('/^[A-Za-z0-9._\/-]{6,}$/', $s) ? $s : '';
    }

    private function normalizeArticleFromComposite($raw): string
    {
        $s = $this->toFlatString($raw);
        if ($s === '') {
            return '';
        }
        $parts = preg_split('/[,;|]/', $s) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/^[A-Za-z0-9._\/-]{6,}$/', $part)) {
                return $part;
            }
        }
        return '';
    }

    private function normalizeClassification($raw): string
    {
        if (is_array($raw)) {
            foreach (['code', 'name', 'value', 'id'] as $key) {
                if (isset($raw[$key]) && $raw[$key] !== null && $raw[$key] !== '') {
                    return $this->toFlatString($raw[$key]);
                }
            }
            foreach ($raw as $item) {
                $s = $this->normalizeClassification($item);
                if ($s !== '') {
                    return $s;
                }
            }
            return '';
        }
        return $this->toFlatString($raw);
    }

    private function toFlatString($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            foreach (['code', 'name', 'value', 'id', 'articleNumber', 'article', 'ean'] as $key) {
                if (isset($value[$key]) && $value[$key] !== null && $value[$key] !== '') {
                    return $this->toFlatString($value[$key]);
                }
            }
            $parts = [];
            foreach ($value as $item) {
                $s = $this->toFlatString($item);
                if ($s !== '') {
                    $parts[] = $s;
                }
            }
            return implode('|', array_slice($parts, 0, 5));
        }
        return trim((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeActive(array $row): ?bool
    {
        foreach (['active', 'isActive', 'is_active'] as $key) {
            if (array_key_exists($key, $row)) {
                return filter_var($row[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            }
        }
        foreach (['deleted', 'isDeleted', 'is_deleted', 'inactive'] as $key) {
            if (array_key_exists($key, $row)) {
                $v = filter_var($row[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                return $v === null ? null : !$v;
            }
        }
        return null;
    }

    private function excerptRow(array $row): array
    {
        $out = [];
        foreach (array_slice(array_keys($row), 0, 20) as $key) {
            $out[$key] = $this->safeJson($row[$key]);
        }
        return $out;
    }

    private function safeJson($value): string
    {
        if ($value === null || is_scalar($value)) {
            return (string) $this->toFlatString($value);
        }
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function boolString(?bool $value): string
    {
        return $value === null ? '' : ($value ? 'true' : 'false');
    }
}
