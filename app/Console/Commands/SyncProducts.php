<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SyncProducts extends Command
{
    protected $signature = 'sync:products';
    protected $description = 'SCAN ONLY: find where ERP stores category WEB + subpart ZZZZZZZZZ. No KMS calls. Clean logs.';

    public function handle(): int
    {
        $correlationId = (string) Str::uuid();

        // Scan tuning
        $maxToScan = 120000;
        $pageSize  = 2000;
        $logEvery  = 10000;

        // Stop conditions (so we don't scan forever)
        $stopAfterWebSamples = 5;     // enough to conclude "which field"
        $stopAfterZzzSamples = 3;     // enough to confirm the Z rule exists
        $stopWhenBothSeen    = true;  // once we have at least 1 WEB and 1 ZZZ, we can stop

        $summary = [
            'scanned' => 0,
            'pages' => 0,
            'invalid_article' => 0,
            'web_found' => 0,
            'web_zzz_found' => 0,
            'errors' => 0,
            'stopped_reason' => null,
        ];

        $webSamples = [];
        $zzzSamples = [];
        $webSourceCounts = [];

        $didLogPageSample = false;
        $didLogDetailSample = false;

        Log::info('[SCAN_START]', [
            'correlation_id' => $correlationId,
            'max_to_scan' => $maxToScan,
            'page_size' => $pageSize,
            'log_every' => $logEvery,
            'note' => 'ERP scan only. Goal: locate WEB/ZZZZZZZZZ field. No KMS calls.',
        ]);

        try {
            $offset = 0;

            while ($offset < $maxToScan) {
                $limit = min($pageSize, $maxToScan - $offset);

                $page = $this->getErpProductsPage($offset, $limit, $correlationId);
                $summary['pages']++;

                if (count($page) === 0) {
                    $summary['stopped_reason'] = 'erp_returned_empty_page';
                    break;
                }

                // Log exactly 1 raw product from the LIST endpoint (once)
                if (!$didLogPageSample) {
                    Log::info('[ERP_PAGE_SAMPLE_PRODUCT]', [
                        'correlation_id' => $correlationId,
                        'sample' => $page[0] ?? null,
                    ]);
                    $didLogPageSample = true;

                    // Also fetch & log exactly 1 detail object for that same product (once)
                    $sampleId = (string) (($page[0]['id'] ?? ''));

                    if (trim($sampleId) !== '') {
                        $detail = $this->getErpProductDetail($sampleId, $correlationId);
                        Log::info('[ERP_DETAIL_SAMPLE_PRODUCT]', [
                            'correlation_id' => $correlationId,
                            'id' => $sampleId,
                            'sample' => $detail,
                        ]);
                        $didLogDetailSample = true;
                    } else {
                        Log::warning('[ERP_DETAIL_SAMPLE_SKIPPED_NO_ID]', [
                            'correlation_id' => $correlationId,
                        ]);
                    }
                }

                foreach ($page as $erp) {
                    $summary['scanned']++;

                    $productCode = (string)($erp['productCode'] ?? '');
                    $id = (string)($erp['id'] ?? '');

                    // Rule: must be exactly 15 digits
                    if (!$this->isValidArticleNumber15Digits($productCode)) {
                        $summary['invalid_article']++;
                        continue;
                    }

                    // IMPORTANT:
                    // WEB/ZZZ might not be present in LIST response.
                    // So: try detection on list fields first; if not found, try detail (but ONLY for 15-digit articles).
                    $cat = $this->detectWebCategory($erp);

                    if (!$cat['is_web']) {
                        // Try detail lookup (this is what makes the script not useless)
                        if (trim($id) !== '') {
                            $detail = $this->getErpProductDetail($id, $correlationId);
                            $cat = $this->detectWebCategory($detail);
                        }
                    }

                    if (!$cat['is_web']) {
                        continue;
                    }

                    // We found WEB somewhere (list or detail)
                    $summary['web_found']++;
                    $src = (string)($cat['source'] ?? 'unknown');
                    $webSourceCounts[$src] = ($webSourceCounts[$src] ?? 0) + 1;

                    if (count($webSamples) < $stopAfterWebSamples) {
                        $webSamples[] = [
                            'productCode' => $productCode,
                            'id' => $id,
                            'source' => $cat['source'],
                            'web_subpart' => $cat['web_subpart'],
                            'matched_text' => $cat['matched_text'],
                        ];

                        Log::info('[FOUND_WEB_SAMPLE]', [
                            'correlation_id' => $correlationId,
                            'sample' => end($webSamples),
                        ]);
                    }

                    // ZZZ rule
                    if (($cat['web_subpart'] ?? null) === 'ZZZZZZZZZ') {
                        $summary['web_zzz_found']++;

                        if (count($zzzSamples) < $stopAfterZzzSamples) {
                            $zzzSamples[] = [
                                'productCode' => $productCode,
                                'id' => $id,
                                'source' => $cat['source'],
                                'matched_text' => $cat['matched_text'],
                            ];

                            Log::info('[FOUND_WEB_ZZZ_SAMPLE]', [
                                'correlation_id' => $correlationId,
                                'sample' => end($zzzSamples),
                            ]);
                        }
                    }

                    // Stop early once we have enough proof
                    if ($stopWhenBothSeen && $summary['web_found'] >= 1 && $summary['web_zzz_found'] >= 1) {
                        $summary['stopped_reason'] = 'found_web_and_zzz';
                        break 2;
                    }

                    if (count($webSamples) >= $stopAfterWebSamples) {
                        $summary['stopped_reason'] = 'found_enough_web_samples';
                        break 2;
                    }

                    if (count($zzzSamples) >= $stopAfterZzzSamples) {
                        $summary['stopped_reason'] = 'found_enough_zzz_samples';
                        break 2;
                    }
                }

                if ($summary['scanned'] % $logEvery === 0) {
                    Log::info('[SCAN_PROGRESS]', [
                        'correlation_id' => $correlationId,
                        'scanned' => $summary['scanned'],
                        'pages' => $summary['pages'],
                        'web_found' => $summary['web_found'],
                        'web_zzz_found' => $summary['web_zzz_found'],
                        'invalid_article' => $summary['invalid_article'],
                    ]);
                }

                if (count($page) < $limit) {
                    $summary['stopped_reason'] = 'erp_returned_last_page';
                    break;
                }

                $offset += $limit;
            }

            Log::info('[SCAN_RESULT]', [
                'correlation_id' => $correlationId,
                'summary' => $summary,
                'web_source_counts' => $webSourceCounts,
                'web_samples' => $webSamples,
                'web_zzz_samples' => $zzzSamples,
                'note' => 'Next step: once we know the exact ERP field, we can filter directly and then re-enable KMS sync safely.',
            ]);

            $this->info('Scan done. Check logs: [ERP_PAGE_SAMPLE_PRODUCT], [ERP_DETAIL_SAMPLE_PRODUCT], [FOUND_*], [SCAN_RESULT].');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $summary['errors']++;

            Log::error('[SCAN_FATAL]', [
                'correlation_id' => $correlationId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            Log::info('[SCAN_RESULT]', [
                'correlation_id' => $correlationId,
                'summary' => $summary,
                'web_source_counts' => $webSourceCounts,
                'web_samples' => $webSamples,
                'web_zzz_samples' => $zzzSamples,
            ]);

            $this->error('Scan failed. Check logs.');
            return self::FAILURE;
        }
    }

    private function httpClient()
    {
        $verify = filter_var(env('SYNC_HTTP_VERIFY_SSL', 'true'), FILTER_VALIDATE_BOOLEAN);

        return Http::withOptions([
            'verify' => $verify,
            'timeout' => 60,
        ]);
    }

    /**
     * ERP list/page fetch.
     * We request a broad set of likely category fields.
     * If ERP ignores some fields, that's fine.
     */
    private function getErpProductsPage(int $offset, int $limit, string $correlationId): array
    {
        $baseUrl = rtrim((string) env('ERP_BASE_URL'), '/');
        $basePath = (string) env('ERP_API_BASE_PATH', '/test/rest/api/v1');
        $admin = (string) env('ERP_ADMIN', '01');

        $user = (string) env('ERP_USER');
        $pass = (string) env('ERP_PASS');

        $url = $baseUrl . $basePath . '/' . $admin . '/products';

        $select = implode(',', [
            'id',
            'productCode',
            'description',
            'searchName',
            'searchKeys',
            'externalProductCode',
            'productGroupCodeExternalProduct',
            'productGroupCode',
            'productGroup',
            'webshopCategory',
            'category',
            'externalStatus',
            // Sometimes category-ish info hides in these:
            'externalStatus',
        ]);

        // Only log the very first request, keep logs clean
        if ($offset === 0) {
            Log::info('[ERP_REQUEST]', [
                'correlation_id' => $correlationId,
                'method' => 'GET',
                'url' => $url,
                'query' => [
                    'offset' => $offset,
                    'limit' => $limit,
                    'select' => $select,
                ],
            ]);
        }

        $resp = $this->httpClient()
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
            ])
            ->get($url, [
                'offset' => $offset,
                'limit' => $limit,
                'select' => $select,
            ]);

        if ($resp->status() !== 200) {
            Log::warning('[ERP_RESPONSE_STATUS]', [
                'correlation_id' => $correlationId,
                'offset' => $offset,
                'limit' => $limit,
                'status' => $resp->status(),
                'body_snippet' => mb_substr((string)$resp->body(), 0, 500),
            ]);
            return [];
        }

        $json = $resp->json();
        if (!is_array($json)) {
            Log::warning('[ERP_BAD_JSON]', [
                'correlation_id' => $correlationId,
                'offset' => $offset,
                'limit' => $limit,
                'body_snippet' => mb_substr((string)$resp->body(), 0, 500),
            ]);
            return [];
        }

        return $this->normalizeErpProductsList($json);
    }

    /**
     * ERP detail fetch for one product by "id" (e.g. "000000000000000,STK").
     * This is where WEB/ZZZ often lives if not in the list.
     */
    private function getErpProductDetail(string $productId, string $correlationId): array
    {
        $baseUrl = rtrim((string) env('ERP_BASE_URL'), '/');
        $basePath = (string) env('ERP_API_BASE_PATH', '/test/rest/api/v1');
        $admin = (string) env('ERP_ADMIN', '01');

        $user = (string) env('ERP_USER');
        $pass = (string) env('ERP_PASS');

        $url = $baseUrl . $basePath . '/' . $admin . '/products/' . rawurlencode($productId);

        // Ask for a few likely category fields; ERP may ignore unknowns
        $select = implode(',', [
            'id',
            'productCode',
            'description',
            'searchName',
            'searchKeys',
            'externalProductCode',
            'productGroupCodeExternalProduct',
            'productGroupCode',
            'productGroup',
            'webshopCategory',
            'category',
            'externalStatus',
        ]);

        $resp = $this->httpClient()
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
            ])
            ->get($url, [
                'select' => $select,
            ]);

        // Keep logs clean: only log if not 200
        if ($resp->status() !== 200) {
            Log::warning('[ERP_DETAIL_RESPONSE_STATUS]', [
                'correlation_id' => $correlationId,
                'id' => $productId,
                'status' => $resp->status(),
                'body_snippet' => mb_substr((string)$resp->body(), 0, 500),
            ]);
            return [];
        }

        $json = $resp->json();
        return is_array($json) ? $json : [];
    }

    private function normalizeErpProductsList(array $raw): array
    {
        foreach (['products', 'data', 'payload'] as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) {
                return array_values($raw[$k]);
            }
        }
        if (array_is_list($raw)) return $raw;
        return [];
    }

    private function isValidArticleNumber15Digits(string $productCode): bool
    {
        return (bool) preg_match('/^\d{15}$/', $productCode);
    }

    /**
     * Detect WEB token and optional subpart token after WEB.
     * If subpart is ZZZZZZZZZ => should be excluded later.
     */
    private function detectWebCategory(array $erp): array
    {
        $candidates = [
            'productGroupCodeExternalProduct' => (string)($erp['productGroupCodeExternalProduct'] ?? ''),
            'productGroupCode' => (string)($erp['productGroupCode'] ?? ''),
            'productGroup' => (string)($erp['productGroup'] ?? ''),
            'webshopCategory' => (string)($erp['webshopCategory'] ?? ''),
            'category' => (string)($erp['category'] ?? ''),
            'externalStatus' => (string)($erp['externalStatus'] ?? ''),
            'externalProductCode' => (string)($erp['externalProductCode'] ?? ''),
            'searchKeys' => (string)($erp['searchKeys'] ?? ''),
            'searchName' => (string)($erp['searchName'] ?? ''),
            'description' => (string)($erp['description'] ?? ''),
        ];

        foreach ($candidates as $field => $value) {
            $valueTrim = trim($value);
            if ($valueTrim === '') continue;

            // WEB as token, optional next token capture
            if (preg_match('/\bWEB\b(?:\s+([A-Z0-9]{1,32}))?/i', $valueTrim, $m)) {
                $sub = isset($m[1]) ? strtoupper($m[1]) : null;

                return [
                    'is_web' => true,
                    'source' => $field,
                    'matched_text' => mb_substr($valueTrim, 0, 200),
                    'web_subpart' => $sub,
                ];
            }
        }

        return [
            'is_web' => false,
            'source' => null,
            'matched_text' => null,
            'web_subpart' => null,
        ];
    }
}
