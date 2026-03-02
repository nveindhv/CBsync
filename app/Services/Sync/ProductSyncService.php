<?php

namespace App\Services\Sync;

use App\Services\ERP\ERPClient;
use App\Services\KMS\KMSClient;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProductSyncService
{
    public function __construct(
        private readonly ERPClient $erp,
        private readonly KMSClient $kms,
        private readonly SyncStateStore $state,
        private readonly int $batchSize,
        private readonly int $maxItems,
        private readonly bool $verifyEachItem,
        private readonly int $erpScanLimit
    ) {}

    public function run(): void
    {
        $correlationId = (string) Str::uuid();

        // Logging controls (via .env)
        $logEachItem = (bool) filter_var(env('SYNC_LOG_EACH_ITEM', 'false'), FILTER_VALIDATE_BOOLEAN);
        $sampleSize  = (int) env('SYNC_LOG_SAMPLE_SIZE', 10);

        $collector = new SyncLogCollector($logEachItem, $sampleSize);

        logger()->info('[SYNC_START]', [
            'module' => 'products',
            'timestamp' => now()->toIso8601String(),
            'correlation_id' => $correlationId,
            'max_items' => $this->maxItems,
            'erp_scan_limit' => $this->erpScanLimit,
            'verify_each_item' => $this->verifyEachItem,
            'log_each_item' => $logEachItem,
            'log_sample_size' => $sampleSize,
        ]);

        $runStart = hrtime(true);

        // DEMO: start offset optional (zodat je sneller bij echte EAN komt)
        $offset = (int) env('SYNC_ERP_OFFSET_START', 0);

        try {
            // 1) Scan ERP tot we maxItems "valid" hebben (valid EAN) of scanLimit op is
            logger()->info('[BATCH_START]', [
                'offset' => $offset,
                'need_valid_items' => $this->maxItems,
                'scan_limit' => $this->erpScanLimit,
                'correlation_id' => $correlationId,
            ]);

            $erpProducts = $this->fetchValidProductsFromErpScan(
                correlationId: $correlationId,
                offset: $offset,
                scanLimit: $this->erpScanLimit,
                needValid: $this->maxItems,
                collector: $collector
            );

            if (count($erpProducts) === 0) {
                logger()->warning('[DEMO_NO_VALID_EAN_FOUND]', [
                    'hint' => 'Increase SYNC_ERP_SCAN_LIMIT (e.g. 1000) or set SYNC_ERP_OFFSET_START to reach real products with valid EAN-13.',
                    'scan_limit' => $this->erpScanLimit,
                    'offset_start' => $offset,
                    'correlation_id' => $correlationId,
                ]);

                $durationMs = (int) ((hrtime(true) - $runStart) / 1e6);

                logger()->info('[SYNC_COMPLETE]', array_merge($collector->summary(), [
                    'duration_ms' => $durationMs,
                    'correlation_id' => $correlationId,
                ]));

                return;
            }

            // 2) Optional: verify snapshot BEFORE (alleen log samenvatting + snippet)
            if ($this->verifyEachItem) {
                $this->logKmsVerifySnapshot('VERIFY_BEFORE', $correlationId);
            }

            // 3) Map naar KMS createUpdate payload (demo minimal)
            $payload = $this->buildKmsCreateUpdatePayload($erpProducts);

            logger()->info('[KMS_CREATEUPDATE_PAYLOAD_SUMMARY]', [
                'count' => count($payload),
                'correlation_id' => $correlationId,
                'sample' => array_slice($payload, 0, min(3, count($payload))),
            ]);

            // 4) Push naar KMS
            $kmsResp = $this->kms->createUpdateProducts($payload, correlationId: $correlationId);
            $collector->markSent(count($payload));

            logger()->info('[KMS_CREATEUPDATE_RESPONSE]', [
                'correlation_id' => $correlationId,
                'response_snippet' => is_array($kmsResp) ? array_slice($kmsResp, 0, 1) : (string) $kmsResp,
            ]);

            // 5) Mark updated for demo (als KMS success true teruggeeft beschouwen we ze “updated”)
            // (Je kunt dit later verfijnen met echte before/after diff op productniveau)
            foreach ($erpProducts as $p) {
                $collector->markUpdated(1, [
                    'article_number' => (string) ($p['productCode'] ?? ''),
                    'ean' => (string) ($p['eanCode'] ?? ''),
                    'correlation_id' => $correlationId,
                ]);
            }

            // 6) Optional: verify snapshot AFTER
            if ($this->verifyEachItem) {
                $this->logKmsVerifySnapshot('VERIFY_AFTER', $correlationId);
            }

            $durationMs = (int) ((hrtime(true) - $runStart) / 1e6);

            logger()->info('[SYNC_COMPLETE]', array_merge($collector->summary(), [
                'duration_ms' => $durationMs,
                'correlation_id' => $correlationId,
            ]));
        } catch (Throwable $e) {
            logger()->error('[SYNC_FATAL]', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            throw $e;
        }
    }

    /**
     * Scan ERP: haal max scanLimit records op en filter totdat we needValid records met valid EAN-13 hebben.
     * Alle SKIP logs gaan via collector -> samenvatting + samples (niet 200 regels).
     */
    private function fetchValidProductsFromErpScan(
        string $correlationId,
        int $offset,
        int $scanLimit,
        int $needValid,
        SyncLogCollector $collector
    ): array {
        logger()->info('[ERP_SCAN_START]', [
            'offset' => $offset,
            'scan_limit' => $scanLimit,
            'select' => 'productCode,description,eanCode,costPrice,price',
            'filter' => '',
            'correlation_id' => $correlationId,
        ]);

        $rows = $this->erp->fetchProducts(
            offset: $offset,
            limit: $scanLimit,
            select: 'productCode,description,eanCode,costPrice,price',
            correlationId: $correlationId
        );

        if (!is_array($rows)) {
            throw new RuntimeException('ERP returned non-array response');
        }

        $collector->incScanned(count($rows));

        // Snippet log (klein!)
        logger()->debug('[ERP_PAYLOAD_SNIPPET]', [
            'correlation_id' => $correlationId,
            'count' => count($rows),
            'snippet' => array_slice($rows, 0, 2),
        ]);

        $valid = [];

        foreach ($rows as $row) {
            $article = (string) ($row['productCode'] ?? '');
            $eanRaw  = $row['eanCode'] ?? null;
            $ean     = is_numeric($eanRaw) ? (string) (int) $eanRaw : (string) $eanRaw;

            if (!$this->isValidEan13($ean)) {
                $collector->markSkipped('INVALID_EAN', [
                    'article_number' => $article,
                    'ean' => (string) $ean,
                    'correlation_id' => $correlationId,
                ]);
                continue;
            }

            $collector->incValid(1);
            $valid[] = $row;

            if (count($valid) >= $needValid) {
                break;
            }
        }

        logger()->info('[ERP_SCAN_RESULT]', [
            'scanned' => count($rows),
            'kept_valid_ean' => count($valid),
            'need_valid_items' => $needValid,
            'correlation_id' => $correlationId,
        ]);

        return $valid;
    }

    private function isValidEan13(?string $ean): bool
    {
        if ($ean === null) return false;

        $ean = trim($ean);

        // Reject 0 / empty
        if ($ean === '' || $ean === '0') return false;

        // Keep only digits
        if (!preg_match('/^\d{13}$/', $ean)) return false;

        // EAN-13 checksum
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean[$i];
            $sum += ($i % 2 === 0) ? $digit : ($digit * 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $ean[12];
    }

    /**
     * Minimal demo mapping naar KMS createUpdate payload.
     * Pas velden aan naar jullie echte KMS schema als nodig.
     */
    private function buildKmsCreateUpdatePayload(array $erpProducts): array
    {
        $payload = [];

        foreach ($erpProducts as $p) {
            $payload[] = [
                'article_number' => (string) ($p['productCode'] ?? ''),
                'ean' => (string) ($p['eanCode'] ?? ''),
                'description' => (string) ($p['description'] ?? ''),
                'cost_price' => (float) ($p['costPrice'] ?? 0),
                'price' => (float) ($p['price'] ?? 0),
            ];
        }

        return $payload;
    }

    /**
     * Snapshot verify: log alleen “summary + snippet”, geen hele dump.
     */
    private function logKmsVerifySnapshot(string $tag, string $correlationId): void
    {
        try {
            $resp = $this->kms->getProductsList(offset: 0, limit: 10, correlationId: $correlationId);

            $count = is_array($resp) ? count($resp) : 0;

            logger()->info("[$tag]", [
                'correlation_id' => $correlationId,
                'count_first_page' => $count,
                'snippet' => is_array($resp) ? array_slice($resp, 0, 2) : (string) $resp,
            ]);
        } catch (Throwable $e) {
            logger()->warning("[$tag" . "_FAILED]", [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
