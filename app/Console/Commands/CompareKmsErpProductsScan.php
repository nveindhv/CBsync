<?php

namespace App\Console\Commands;

use App\Services\ERP\ERPClient;
use App\Services\KMS\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CompareKmsErpProductsScan extends Command
{
    protected $signature = 'compare:kms-erp:products:scan
        {--kms-limit=5 : Number of KMS products to fetch}
        {--kms-offset=0 : Offset for KMS product listing}
        {--erp-start-offset=0 : ERP offset to start scanning from}
        {--erp-scan-limit=200 : ERP page size per scan request}
        {--erp-max-offset=120000 : Stop scanning ERP after this offset}
        {--erp-first5 : Also fetch ERP first 5 products (offset 0) as baseline}
        {--dump : Dump JSON result to storage/app/compare_dump}
    ';

    protected $description = 'Fetch N products from KMS, find matching ERP products by productCode or EAN by scanning ERP pages, optionally dump results.';

    public function handle(KmsClient $kms, ERPClient $erp): int
    {
        $correlationId = (string) Str::uuid();
        $this->line('CorrelationId: ' . $correlationId);

        $kmsLimit = (int) $this->option('kms-limit');
        $kmsOffset = (int) $this->option('kms-offset');

        $erpStartOffset = (int) $this->option('erp-start-offset');
        $erpScanLimit = (int) $this->option('erp-scan-limit');
        $erpMaxOffset = (int) $this->option('erp-max-offset');

        if ($kmsLimit < 1) {
            $this->error('kms-limit must be >= 1');
            return self::FAILURE;
        }
        if ($erpScanLimit < 1) {
            $this->error('erp-scan-limit must be >= 1');
            return self::FAILURE;
        }
        if ($erpMaxOffset < $erpStartOffset) {
            $this->error('erp-max-offset must be >= erp-start-offset');
            return self::FAILURE;
        }

        $this->line("Fetching KMS products (offset={$kmsOffset}, limit={$kmsLimit})...");

        // KMS: POST kms/product/getProducts
        $kmsResponse = $kms->post('kms/product/getProducts', [
            'offset' => $kmsOffset,
            'limit' => $kmsLimit,
        ]);

        // Response is typically an object keyed by product id.
        $kmsProducts = [];
        foreach ((array) $kmsResponse as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $article = (string) ($row['articleNumber'] ?? '');
            $ean = (string) ($row['ean'] ?? '');
            $kmsProducts[] = [
                'kms_id' => $row['id'] ?? $key,
                'articleNumber' => $article,
                'ean' => $ean,
                'name' => $row['name'] ?? null,
                'raw' => $row,
            ];
        }

        if (count($kmsProducts) === 0) {
            $this->warn('No KMS products returned for this page.');
        }

        $targetsByArticle = [];
        $targetsByEan = [];
        foreach ($kmsProducts as $p) {
            if (!empty($p['articleNumber'])) {
                $targetsByArticle[$p['articleNumber']] = true;
            }
            if (!empty($p['ean'])) {
                $targetsByEan[$this->normalizeEan($p['ean'])] = true;
            }
        }

        $matches = [];
        $scanned = 0;
        $offset = $erpStartOffset;

        $this->line("Scanning ERP products from offset={$erpStartOffset} to offset={$erpMaxOffset} (page size={$erpScanLimit})...");

        while ($offset <= $erpMaxOffset && (count($matches) < count($kmsProducts))) {
            $erpRows = $erp->get('products', [
                'offset' => $offset,
                'limit' => $erpScanLimit,
            ]);

            $rows = is_array($erpRows) ? $erpRows : (array) $erpRows;
            $count = count($rows);
            $scanned += $count;

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $erpProductCode = (string) ($row['productCode'] ?? '');
                $erpEan = $this->normalizeEan((string) ($row['eanCodeAsText'] ?? ($row['eanCode'] ?? '')));

                $hit = false;
                if ($erpProductCode !== '' && isset($targetsByArticle[$erpProductCode])) {
                    $hit = true;
                }
                if (!$hit && $erpEan !== '' && isset($targetsByEan[$erpEan])) {
                    $hit = true;
                }

                if ($hit) {
                    $matches[] = [
                        'erp' => $row,
                        'match' => [
                            'productCode' => $erpProductCode,
                            'ean' => $erpEan,
                        ],
                    ];
                }

                if (count($matches) >= count($kmsProducts)) {
                    break;
                }
            }

            $this->line("  ERP scan: offset={$offset} fetched={$count} scanned_total={$scanned} matches=" . count($matches));

            if ($count < $erpScanLimit) {
                // End reached earlier than expected.
                break;
            }

            $offset += $erpScanLimit;
        }

        $erpFirst5 = null;
        if ((bool) $this->option('erp-first5')) {
            $this->line('Fetching ERP first 5 products (offset=0)...');
            $erpFirst5 = $erp->get('products', ['offset' => 0, 'limit' => 5]);
        }

        $result = [
            'correlationId' => $correlationId,
            'params' => [
                'kms' => ['offset' => $kmsOffset, 'limit' => $kmsLimit],
                'erp' => ['startOffset' => $erpStartOffset, 'scanLimit' => $erpScanLimit, 'maxOffset' => $erpMaxOffset],
                'erpFirst5' => (bool) $this->option('erp-first5'),
            ],
            'kmsProducts' => $kmsProducts,
            'erpMatches' => $matches,
            'erpFirst5Products' => $erpFirst5,
            'stats' => [
                'kms_count' => count($kmsProducts),
                'erp_scanned' => $scanned,
                'erp_matches' => count($matches),
                'stopped_at_offset' => $offset,
            ],
        ];

        if ((bool) $this->option('dump')) {
            $dir = storage_path('app/compare_dump');
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'compare_kms_erp_products_' . date('Ymd_His') . '_' . substr($correlationId, 0, 8) . '.json';
            file_put_contents($file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Dumped to ' . $file);
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function normalizeEan(string $ean): string
    {
        $ean = preg_replace('/\D+/', '', $ean) ?? '';
        // Keep leading zeros but remove empty.
        return $ean;
    }
}
