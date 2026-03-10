<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncProductsKmsAudit extends Command
{
    protected $signature = 'sync:products:kms-audit
        {--target=0 : Stop after N eligible products (0 = no explicit stop)}
        {--offset=0 : ERP start offset}
        {--page-size=200 : ERP page size}
        {--max-pages=500 : Max ERP pages to scan}
        {--only-codes= : Comma-separated ERP productCodes to process}
        {--contains= : Only process ERP rows where description/searchName/searchKeys contains this text}
        {--classification-mode=web : web|none. web = require WEB and skip WEB/ZZZZZZZZZ}
        {--skip-web-zzz=1 : In web mode, skip WEB + ZZZZZZZZZ}
        {--after-wait=0 : Seconds to wait before AFTER verify}
        {--live : Actually call KMS createUpdate}
        {--dry-run : Build and log payloads without calling KMS}
        {--write-json : Write JSON/CSV/JSONL summary files}
        {--dump-payloads : Dump one payload JSON per processed ERP row}
        {--debug : Verbose output}';

    protected $description = 'ERP -> KMS sync/audit runner with per-row visibility verification, payload logging, page/offset tracking, and classification filtering.';

    public function handle(KmsClient $kms): int
    {
        $live = (bool) $this->option('live');
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $writeJson = (bool) $this->option('write-json');
        $dumpPayloads = (bool) $this->option('dump-payloads');
        $afterWait = max(0, (int) $this->option('after-wait'));
        $target = max(0, (int) $this->option('target'));
        $offset = max(0, (int) $this->option('offset'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));
        $contains = trim((string) $this->option('contains'));
        $classificationMode = strtolower(trim((string) $this->option('classification-mode')));
        $skipWebZzz = (string) $this->option('skip-web-zzz') !== '0';
        $correlationId = (string) Str::uuid();
        $startedAt = now();

        if ($live && $dryRun) {
            $this->error('Gebruik óf --live óf --dry-run, niet beide.');
            return self::FAILURE;
        }

        $mode = $live ? 'live' : 'dry-run';
        $runStamp = now()->format('Ymd_His');
        $baseReportDir = storage_path('app/private/kms_audit_sync');
        $runDir = $baseReportDir . DIRECTORY_SEPARATOR . 'run_' . $runStamp;
        $payloadDir = $runDir . DIRECTORY_SEPARATOR . 'payloads';
        if ($writeJson || $dumpPayloads) {
            File::ensureDirectoryExists($runDir);
        }
        if ($dumpPayloads) {
            File::ensureDirectoryExists($payloadDir);
        }

        try {
            $token = $kms->getAccessToken();
            if ($debug) {
                $this->line('[KMS_TOKEN_OK] length=' . strlen($token));
            }
        } catch (\Throwable $e) {
            $this->error('Could not obtain KMS token: ' . $e->getMessage());
            return self::FAILURE;
        }

        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');
        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return self::FAILURE;
        }

        $httpErp = Http::timeout(120)
            ->acceptJson()
            ->withOptions([
                'verify' => false,
                'connect_timeout' => 30,
            ])
            ->retry(2, 1000, throw: false)
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass));

        $summary = [
            'correlation_id' => $correlationId,
            'started_at' => $startedAt->toIso8601String(),
            'mode' => $mode,
            'target' => $target,
            'offset' => $offset,
            'page_size' => $pageSize,
            'max_pages' => $maxPages,
            'classification_mode' => $classificationMode,
            'skip_web_zzz' => $skipWebZzz,
            'contains' => $contains,
            'counts' => [
                'scanned' => 0,
                'eligible' => 0,
                'processed' => 0,
                'created_visible' => 0,
                'updated_visible' => 0,
                'success_not_visible' => 0,
                'kms_create_exception' => 0,
                'erp_error' => 0,
                'skipped_classification' => 0,
                'skipped_contains' => 0,
                'skipped_invalid_article' => 0,
                'skipped_inactive' => 0,
                'skipped_missing_name' => 0,
            ],
        ];

        $jsonlPath = $runDir . DIRECTORY_SEPARATOR . 'rows.jsonl';
        $csvPath = $runDir . DIRECTORY_SEPARATOR . 'rows.csv';
        if ($writeJson) {
            File::put($jsonlPath, '');
            File::put($csvPath, $this->csvHeader());
        }

        $this->line('=== ERP -> KMS AUDIT SYNC ===');
        $this->line('Correlation : ' . $correlationId);
        $this->line('Mode        : ' . strtoupper($mode));
        $this->line('Page size   : ' . $pageSize);
        $this->line('Max pages   : ' . $maxPages);
        $this->line('Offset      : ' . $offset);
        if ($contains !== '') {
            $this->line('Contains    : ' . $contains);
        }
        if ((string) $this->option('only-codes') !== '') {
            $this->line('Only codes  : ' . $this->option('only-codes'));
        }

        $onlyCodes = $this->parseOnlyCodes((string) $this->option('only-codes'));

        if (!empty($onlyCodes)) {
            foreach ($onlyCodes as $index => $code) {
                $erp = $this->erpFetchSingleProduct($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $code);
                if ($erp === null) {
                    $summary['counts']['erp_error']++;
                    $row = $this->baseRow($correlationId, 0, $offset, $offset + $index, $code);
                    $row['status'] = 'ERP_FETCH_SINGLE_FAILED';
                    $row['reason'] = 'Could not fetch ERP product by productCode';
                    $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
                    continue;
                }
                $stop = $this->processProduct(
                    $kms,
                    $httpErp,
                    $erpBaseUrl,
                    $erpApiBasePath,
                    $erpAdmin,
                    $erp,
                    1,
                    $offset,
                    $index,
                    $contains,
                    $classificationMode,
                    $skipWebZzz,
                    $afterWait,
                    $live,
                    $dumpPayloads,
                    $payloadDir,
                    $writeJson,
                    $jsonlPath,
                    $csvPath,
                    $debug,
                    $summary,
                    $correlationId
                );
                if ($stop) {
                    break;
                }
            }
        } else {
            for ($page = 0; $page < $maxPages; $page++) {
                if ($target > 0 && $summary['counts']['processed'] >= $target) {
                    break;
                }

                $pageOffset = $offset + ($page * $pageSize);
                if ($debug) {
                    $this->line('[ERP_PAGE] page=' . ($page + 1) . ' offset=' . $pageOffset . ' limit=' . $pageSize);
                }

                $items = $this->erpFetchProductsPage($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $pageOffset, $pageSize);
                if ($items === null) {
                    $summary['counts']['erp_error']++;
                    break;
                }
                if ($items === []) {
                    break;
                }

                foreach ($items as $rowIndex => $erp) {
                    if (!is_array($erp)) {
                        continue;
                    }
                    if ($target > 0 && $summary['counts']['processed'] >= $target) {
                        break 2;
                    }

                    $stop = $this->processProduct(
                        $kms,
                        $httpErp,
                        $erpBaseUrl,
                        $erpApiBasePath,
                        $erpAdmin,
                        $erp,
                        $page + 1,
                        $pageOffset,
                        $rowIndex,
                        $contains,
                        $classificationMode,
                        $skipWebZzz,
                        $afterWait,
                        $live,
                        $dumpPayloads,
                        $payloadDir,
                        $writeJson,
                        $jsonlPath,
                        $csvPath,
                        $debug,
                        $summary,
                        $correlationId
                    );
                    if ($stop) {
                        break 2;
                    }
                }

                if (count($items) < $pageSize) {
                    break;
                }
            }
        }

        $summary['finished_at'] = now()->toIso8601String();
        $summaryPath = $runDir . DIRECTORY_SEPARATOR . 'summary.json';
        if ($writeJson) {
            File::put($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($writeJson) {
            $this->info('REPORT DIR : ' . $runDir);
        }

        return self::SUCCESS;
    }

    private function processProduct(
        KmsClient $kms,
        $httpErp,
        string $erpBaseUrl,
        string $erpApiBasePath,
        string $erpAdmin,
        array $erp,
        int $page,
        int $pageOffset,
        int $rowIndex,
        string $contains,
        string $classificationMode,
        bool $skipWebZzz,
        int $afterWait,
        bool $live,
        bool $dumpPayloads,
        string $payloadDir,
        bool $writeJson,
        string $jsonlPath,
        string $csvPath,
        bool $debug,
        array &$summary,
        string $correlationId
    ): bool {
        $summary['counts']['scanned']++;

        $article = trim((string) ($erp['productCode'] ?? ''));
        $row = $this->baseRow($correlationId, $page, $pageOffset, $pageOffset + $rowIndex, $article);
        $row['timestamp_utc'] = now('UTC')->format('Y-m-d H:i:s');
        $row['erp_index_in_page'] = $rowIndex;
        $row['erp_modify_date'] = (string) ($erp['modifyDate'] ?? '');
        $row['erp_ean'] = $this->normalizeEan($erp['eanCode'] ?? '');
        $row['erp_name_raw'] = trim((string) ($erp['searchName'] ?? ($erp['description'] ?? '')));

        if (!$this->isLikelyArticle($article)) {
            $summary['counts']['skipped_invalid_article']++;
            $row['status'] = 'SKIPPED_INVALID_ARTICLE';
            $row['reason'] = 'productCode missing or not numeric 9-15 digits';
            $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
            return false;
        }

        if ((bool) ($erp['inactive'] ?? false)) {
            $summary['counts']['skipped_inactive']++;
            $row['status'] = 'SKIPPED_INACTIVE';
            $row['reason'] = 'ERP row marked inactive';
            $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
            return false;
        }

        if ($contains !== '') {
            $hay = strtoupper(trim(implode(' ', [
                (string) ($erp['description'] ?? ''),
                (string) ($erp['searchName'] ?? ''),
                (string) ($erp['searchKeys'] ?? ''),
            ])));
            if (strpos($hay, strtoupper($contains)) === false) {
                $summary['counts']['skipped_contains']++;
                $row['status'] = 'SKIPPED_CONTAINS';
                $row['reason'] = 'contains filter mismatch';
                $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
                return false;
            }
        }

        $classification = $this->classificationDecision($httpErp, $erpBaseUrl, $erpApiBasePath, $erpAdmin, $article, $classificationMode, $skipWebZzz);
        $row['classification_status'] = $classification['status'];
        $row['classification_reason'] = $classification['reason'];
        $row['classification_has_web'] = $classification['has_web'] ? 1 : 0;
        $row['classification_has_web_zzz'] = $classification['has_web_zzz'] ? 1 : 0;
        if (!$classification['eligible']) {
            if ($classification['status'] === 'ERP_CLASSIFICATION_ERROR') {
                $summary['counts']['erp_error']++;
            } else {
                $summary['counts']['skipped_classification']++;
            }
            $row['status'] = $classification['status'];
            $row['reason'] = $classification['reason'];
            $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
            return false;
        }

        $payload = $this->buildPayloadFromErp($erp);
        $payloadProduct = $payload['products'][0];
        $row['payload_article_number'] = (string) ($payloadProduct['article_number'] ?? '');
        $row['payload_ean'] = (string) ($payloadProduct['ean'] ?? '');
        $row['payload_type_number'] = (string) ($payloadProduct['type_number'] ?? '');
        $row['payload_name'] = (string) ($payloadProduct['name'] ?? '');
        $row['payload_keys'] = implode('|', array_keys($payloadProduct));

        if (trim((string) ($payloadProduct['name'] ?? '')) === '') {
            $summary['counts']['skipped_missing_name']++;
            $row['status'] = 'SKIPPED_MISSING_NAME';
            $row['reason'] = 'Final payload name is empty';
            $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
            return false;
        }

        if ($dumpPayloads) {
            $payloadPath = $payloadDir . DIRECTORY_SEPARATOR . $article . '.json';
            File::put($payloadPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $row['payload_path'] = $payloadPath;
        }

        $summary['counts']['eligible']++;
        $summary['counts']['processed']++;

        $before = $this->verifyKmsVisibility($kms, $row['payload_article_number'], $row['payload_ean']);
        $row['before_article_count'] = $before['article_count'];
        $row['before_ean_count'] = $before['ean_count'];
        $row['before_visible'] = $before['visible'] ? 1 : 0;

        if ($debug) {
            $this->line('');
            $this->line('[SYNC] article=' . $article . ' page=' . $page . ' abs_offset=' . ($pageOffset + $rowIndex));
            $this->line('[BEFORE] article=' . $before['article_count'] . ' ean=' . $before['ean_count']);
            $this->line('[PAYLOAD] ' . json_encode($payloadProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $createResponse = ['dry_run' => true];
        if ($live) {
            try {
                $createResponse = $kms->post('kms/product/createUpdate', $payload);
            } catch (\Throwable $e) {
                $summary['counts']['kms_create_exception']++;
                $row['status'] = 'KMS_CREATE_EXCEPTION';
                $row['reason'] = $e->getMessage();
                $row['create_response'] = $this->encodeCell(['exception' => $e->getMessage()]);
                $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
                return false;
            }
        }

        if ($afterWait > 0) {
            sleep($afterWait);
        }

        $after = $this->verifyKmsVisibility($kms, $row['payload_article_number'], $row['payload_ean']);
        $row['after_article_count'] = $after['article_count'];
        $row['after_ean_count'] = $after['ean_count'];
        $row['after_visible'] = $after['visible'] ? 1 : 0;
        $row['create_response'] = $this->encodeCell($createResponse);
        $row['create_success_flag'] = is_array($createResponse) && (($createResponse['success'] ?? null) === true) ? 1 : 0;

        if (!$before['visible'] && $after['visible']) {
            $row['status'] = 'CREATED_VISIBLE';
            $row['reason'] = 'Not visible before, visible after createUpdate verify';
            $summary['counts']['created_visible']++;
        } elseif ($before['visible'] && $after['visible']) {
            $row['status'] = 'UPDATED_VISIBLE';
            $row['reason'] = 'Visible before and after createUpdate verify';
            $summary['counts']['updated_visible']++;
        } else {
            $row['status'] = 'SUCCESS_NOT_VISIBLE';
            $row['reason'] = 'createUpdate did not become visible via getProducts on article/EAN';
            $summary['counts']['success_not_visible']++;
        }

        if ($debug) {
            $this->line('[AFTER] article=' . $after['article_count'] . ' ean=' . $after['ean_count'] . ' => ' . $row['status']);
        }

        $this->persistRow($row, $writeJson, $jsonlPath, $csvPath);
        return false;
    }

    private function buildPayloadFromErp(array $erp): array
    {
        $article = trim((string) ($erp['productCode'] ?? ''));
        $ean = $this->normalizeEan($erp['eanCode'] ?? '');
        $name = trim((string) ($erp['searchName'] ?? ($erp['description'] ?? $article)));
        $description = trim((string) ($erp['description'] ?? ''));
        $unit = trim((string) ($erp['unitCode'] ?? '')) ?: 'STK';
        $brand = trim((string) ($erp['brand'] ?? ''));
        if ($brand === '') {
            $brand = $this->deriveBrand($name, $description);
        }
        [$size, $color] = $this->extractSizeColor((string) ($erp['searchKeys'] ?? ''));
        $family9 = substr($article, 0, min(9, strlen($article)));
        $typeName = $this->deriveTypeName($name, $description);

        $row = [
            'article_number' => $article,
            'articleNumber' => $article,
            'name' => $name,
            'description' => $description,
            'purchase_price' => $this->toFloat($erp['costPrice'] ?? null),
            'price' => $this->toFloat($erp['price'] ?? null),
            'unit' => $unit,
            'brand' => $brand,
            'is_active' => (bool) ($erp['inactive'] ?? false) ? 0 : 1,
            'is_deleted' => 0,
            'type_number' => $family9,
            'typeNumber' => $family9,
            'type_name' => $typeName,
            'typeName' => $typeName,
        ];

        if ($ean !== '') {
            $row['ean'] = $ean;
        }
        if ($color !== '') {
            $row['color'] = $color;
        }
        if ($size !== '') {
            $row['size'] = $size;
        }
        if (isset($erp['supplierName']) && trim((string) $erp['supplierName']) !== '') {
            $row['supplier_name'] = trim((string) $erp['supplierName']);
        }
        if (isset($erp['modifyDate']) && trim((string) $erp['modifyDate']) !== '') {
            $row['creation_date'] = $this->normalizeDateTime((string) $erp['modifyDate']);
        }

        return ['products' => [$row]];
    }

    private function classificationDecision($httpErp, string $base, string $apiPath, string $admin, string $article, string $mode, bool $skipWebZzz): array
    {
        if ($mode === 'none') {
            return [
                'eligible' => true,
                'status' => 'CLASSIFICATION_BYPASSED',
                'reason' => 'classification-mode=none',
                'has_web' => false,
                'has_web_zzz' => false,
            ];
        }

        $rows = $this->erpFetchClassifications($httpErp, $base, $apiPath, $admin, $article);
        if ($rows === null) {
            return [
                'eligible' => false,
                'status' => 'ERP_CLASSIFICATION_ERROR',
                'reason' => 'Could not fetch productClassifications',
                'has_web' => false,
                'has_web_zzz' => false,
            ];
        }

        $hasWeb = false;
        $hasWebZzz = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cat = (string) ($row['productCategoryCode'] ?? '');
            $grp = (string) ($row['productGroupCode'] ?? '');
            if ($cat === 'WEB') {
                $hasWeb = true;
                if ($grp === 'ZZZZZZZZZ') {
                    $hasWebZzz = true;
                }
            }
        }

        if (!$hasWeb) {
            return [
                'eligible' => false,
                'status' => 'SKIPPED_CLASSIFICATION',
                'reason' => 'No WEB classification',
                'has_web' => false,
                'has_web_zzz' => false,
            ];
        }

        if ($skipWebZzz && $hasWebZzz) {
            return [
                'eligible' => false,
                'status' => 'SKIPPED_CLASSIFICATION',
                'reason' => 'WEB + ZZZZZZZZZ excluded',
                'has_web' => true,
                'has_web_zzz' => true,
            ];
        }

        return [
            'eligible' => true,
            'status' => 'CLASSIFICATION_OK',
            'reason' => 'WEB classification present',
            'has_web' => $hasWeb,
            'has_web_zzz' => $hasWebZzz,
        ];
    }

    private function verifyKmsVisibility(KmsClient $kms, string $article, string $ean): array
    {
        $articleRows = [];
        $eanRows = [];

        try {
            $articleRaw = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 10,
                'articleNumber' => $article,
            ]);
            $articleRows = $this->normalizeRows($articleRaw);
        } catch (\Throwable $e) {
            // keep empty; audit row already captures lack of visibility, not all getProducts errors are fatal
        }

        if ($ean !== '') {
            try {
                $eanRaw = $kms->post('kms/product/getProducts', [
                    'offset' => 0,
                    'limit' => 10,
                    'ean' => $ean,
                ]);
                $eanRows = $this->normalizeRows($eanRaw);
            } catch (\Throwable $e) {
                // keep empty
            }
        }

        return [
            'article_count' => count($articleRows),
            'ean_count' => count($eanRows),
            'visible' => count($articleRows) > 0 || count($eanRows) > 0,
        ];
    }

    private function normalizeRows($response): array
    {
        if (!is_array($response)) {
            return [];
        }
        if (isset($response['products']) && is_array($response['products'])) {
            return array_values(array_filter($response['products'], 'is_array'));
        }
        if (isset($response['rows']) && is_array($response['rows'])) {
            return array_values(array_filter($response['rows'], 'is_array'));
        }
        if (isset($response['data']) && is_array($response['data'])) {
            if (array_is_list($response['data'])) {
                return array_values(array_filter($response['data'], 'is_array'));
            }
            if (isset($response['data']['products']) && is_array($response['data']['products'])) {
                return array_values(array_filter($response['data']['products'], 'is_array'));
            }
        }
        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }
        return [];
    }

    private function erpFetchProductsPage($httpErp, string $base, string $apiPath, string $admin, int $offset, int $limit): ?array
    {
        $url = $base . $apiPath . '/' . $admin . '/products';
        try {
            $resp = $httpErp->get($url, ['offset' => $offset, 'limit' => $limit]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$resp->ok()) {
            return null;
        }
        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    private function erpFetchSingleProduct($httpErp, string $base, string $apiPath, string $admin, string $productCode): ?array
    {
        $url = $base . $apiPath . '/' . $admin . '/products';
        try {
            $resp = $httpErp->get($url, [
                'offset' => 0,
                'limit' => 50,
                'filter' => "productCode EQ '{$productCode}'",
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$resp->ok()) {
            return null;
        }
        $items = $resp->json();
        if (!is_array($items) || count($items) === 0) {
            return null;
        }
        return is_array($items[0]) ? $items[0] : null;
    }

    private function erpFetchClassifications($httpErp, string $base, string $apiPath, string $admin, string $productCode): ?array
    {
        $url = $base . $apiPath . '/' . $admin . '/productClassifications';
        try {
            $resp = $httpErp->get($url, [
                'offset' => 0,
                'limit' => 250,
                'filter' => "productCode EQ '{$productCode}'",
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$resp->ok()) {
            return null;
        }
        $items = $resp->json();
        return is_array($items) ? $items : [];
    }

    private function parseOnlyCodes(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return array_values(array_unique($out));
    }

    private function normalizeEan($value): string
    {
        $ean = preg_replace('/\D+/', '', (string) $value);
        return $ean ?: '';
    }

    private function deriveBrand(string $name, string $description): string
    {
        $source = trim($name) !== '' ? trim($name) : trim($description);
        if ($source === '') {
            return '';
        }
        $first = preg_split('/\s+/', $source)[0] ?? '';
        return strtoupper(trim((string) $first));
    }

    private function deriveTypeName(string $name, string $description): string
    {
        $source = trim($name) !== '' ? trim($name) : trim($description);
        return $source;
    }

    private function toFloat($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return (float) str_replace(',', '.', (string) $value);
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            return date('Y-m-d H:i:s', strtotime($value));
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function extractSizeColor(string $searchKeys): array
    {
        $s = ' ' . strtolower($searchKeys) . ' ';
        $size = '';
        foreach (['xs','s','m','l','xl','xxl','3xl','4xl','5xl','6xl','7xl','8xl'] as $cand) {
            if (preg_match('/\b' . preg_quote($cand, '/') . '\b/', $s)) {
                $size = strtoupper($cand);
                break;
            }
        }
        $color = '';
        if ($size !== '') {
            if (preg_match('/\b' . strtolower($size) . '\b\s+([a-z0-9\-\/]+)/', $s, $m)) {
                $color = trim((string) ($m[1] ?? ''));
            }
        }
        return [$size, $color];
    }

    private function isLikelyArticle(string $article): bool
    {
        return (bool) preg_match('/^\d{9,15}$/', $article);
    }

    private function baseRow(string $correlationId, int $page, int $pageOffset, int $absOffset, string $article): array
    {
        return [
            'correlation_id' => $correlationId,
            'page' => $page,
            'page_offset' => $pageOffset,
            'absolute_offset' => $absOffset,
            'article' => $article,
            'status' => '',
            'reason' => '',
            'timestamp_utc' => '',
            'erp_index_in_page' => 0,
            'erp_modify_date' => '',
            'erp_ean' => '',
            'erp_name_raw' => '',
            'classification_status' => '',
            'classification_reason' => '',
            'classification_has_web' => 0,
            'classification_has_web_zzz' => 0,
            'payload_article_number' => '',
            'payload_ean' => '',
            'payload_type_number' => '',
            'payload_name' => '',
            'payload_keys' => '',
            'payload_path' => '',
            'before_article_count' => 0,
            'before_ean_count' => 0,
            'before_visible' => 0,
            'after_article_count' => 0,
            'after_ean_count' => 0,
            'after_visible' => 0,
            'create_success_flag' => 0,
            'create_response' => '',
        ];
    }

    private function persistRow(array $row, bool $writeJson, string $jsonlPath, string $csvPath): void
    {
        if (!$writeJson) {
            return;
        }
        File::append($jsonlPath, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        File::append($csvPath, $this->csvLine($row));
    }

    private function csvHeader(): string
    {
        return implode(',', [
            'timestamp_utc','correlation_id','page','page_offset','absolute_offset','erp_index_in_page','article','erp_ean','erp_name_raw',
            'classification_status','classification_reason','classification_has_web','classification_has_web_zzz',
            'payload_article_number','payload_ean','payload_type_number','payload_name','payload_keys','payload_path',
            'before_article_count','before_ean_count','before_visible','after_article_count','after_ean_count','after_visible',
            'create_success_flag','status','reason','erp_modify_date','create_response'
        ]) . PHP_EOL;
    }

    private function csvLine(array $row): string
    {
        $fields = [
            'timestamp_utc','correlation_id','page','page_offset','absolute_offset','erp_index_in_page','article','erp_ean','erp_name_raw',
            'classification_status','classification_reason','classification_has_web','classification_has_web_zzz',
            'payload_article_number','payload_ean','payload_type_number','payload_name','payload_keys','payload_path',
            'before_article_count','before_ean_count','before_visible','after_article_count','after_ean_count','after_visible',
            'create_success_flag','status','reason','erp_modify_date','create_response'
        ];
        $out = [];
        foreach ($fields as $field) {
            $out[] = $this->csvEscape((string) ($row[$field] ?? ''));
        }
        return implode(',', $out) . PHP_EOL;
    }

    private function csvEscape(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = str_replace('"', '""', $value);
        return '"' . $value . '"';
    }

    private function encodeCell($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
