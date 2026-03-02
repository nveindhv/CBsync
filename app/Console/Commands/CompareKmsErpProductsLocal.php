<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class CompareKmsErpProductsLocal extends Command
{
    /**
     * We intentionally DO NOT use any HTTP clients here.
     * We simply call the already-working artisan GET commands and read their dumped JSON.
     */
    protected $signature = 'compare:kms-erp:products:local
        {--kms-limit=5 : Number of KMS products to fetch}
        {--kms-offset=0 : KMS offset}
        {--erp-start-offset=0 : ERP offset to start scanning from}
        {--erp-scan-limit=200 : ERP page size per request while scanning}
        {--erp-max-offset=120000 : Stop scanning ERP after this offset}
        {--erp-first5 : Also fetch ERP first 5 products (offset=0,limit=5)}
        {--dump : Write JSON output to storage/app/compare_dump}
    ';

    protected $description = 'Compare KMS products with ERP products by calling existing GET commands and matching on articleNumber/productCode or EAN (no new clients/config).';

    public function handle(): int
    {
        $cid = (string) Str::uuid();
        $this->info("CorrelationId: {$cid}");

        $kmsLimit = (int) $this->option('kms-limit');
        $kmsOffset = (int) $this->option('kms-offset');

        $erpStartOffset = (int) $this->option('erp-start-offset');
        $erpScanLimit = (int) $this->option('erp-scan-limit');
        $erpMaxOffset = (int) $this->option('erp-max-offset');

        $includeErpFirst5 = (bool) $this->option('erp-first5');
        $doDump = (bool) $this->option('dump');

        // 1) Fetch KMS products via existing command
        $this->line("Fetching KMS products (offset={$kmsOffset}, limit={$kmsLimit})...");
        Artisan::call('kms:get:products', [
            '--limit' => $kmsLimit,
            '--offset' => $kmsOffset,
            '--max-pages' => 1,
        ]);

        $kmsDumpPath = storage_path("app/kms_dump/products/offset_{$kmsOffset}_limit_{$kmsLimit}.json");
        if (!is_file($kmsDumpPath)) {
            $this->error("KMS dump not found at: {$kmsDumpPath}");
            $this->error('Run `php artisan kms:get:products --limit='.$kmsLimit.' --offset='.$kmsOffset.' --max-pages=1` once and try again.');
            return self::FAILURE;
        }

        $kmsRaw = json_decode((string) file_get_contents($kmsDumpPath), true);
        if (!is_array($kmsRaw)) {
            $this->error("KMS dump is not valid JSON: {$kmsDumpPath}");
            return self::FAILURE;
        }

        $kmsProducts = $this->normalizeKmsProducts($kmsRaw);
        if (count($kmsProducts) === 0) {
            $this->warn('No KMS products found in dump (unexpected).');
        }

        // 2) Optionally fetch ERP first 5
        $erpFirst5 = null;
        if ($includeErpFirst5) {
            $this->line('Fetching ERP first 5 products (offset=0, limit=5)...');
            Artisan::call('erp:get:products', [
                '--limit' => 5,
                '--offset' => 0,
                '--max-pages' => 1,
            ]);
            $erpFirst5Path = storage_path('app/erp_dump/products/offset_0_limit_5.json');
            if (is_file($erpFirst5Path)) {
                $erpFirst5 = json_decode((string) file_get_contents($erpFirst5Path), true);
            }
        }

        // 3) Scan ERP pages and match
        $this->line("Scanning ERP products from offset={$erpStartOffset} to maxOffset={$erpMaxOffset} (pageLimit={$erpScanLimit})...");

        $targets = $kmsProducts;
        $unmatched = array_fill_keys(array_keys($targets), true);
        $matches = [];

        for ($offset = $erpStartOffset; $offset <= $erpMaxOffset; $offset += $erpScanLimit) {
            if (count($unmatched) === 0) {
                break;
            }

            Artisan::call('erp:get:products', [
                '--limit' => $erpScanLimit,
                '--offset' => $offset,
                '--max-pages' => 1,
            ]);

            $erpDumpPath = storage_path("app/erp_dump/products/offset_{$offset}_limit_{$erpScanLimit}.json");
            if (!is_file($erpDumpPath)) {
                $this->warn("ERP dump not found at: {$erpDumpPath} (skipping page)");
                continue;
            }

            $erpPage = json_decode((string) file_get_contents($erpDumpPath), true);
            if (!is_array($erpPage)) {
                $this->warn("ERP dump invalid JSON at: {$erpDumpPath} (skipping page)");
                continue;
            }

            // index ERP page for faster lookup
            $erpIndexByCode = [];
            $erpIndexByEan = [];
            foreach ($erpPage as $row) {
                if (!is_array($row)) continue;
                $code = (string)($row['productCode'] ?? '');
                if ($code !== '') {
                    $erpIndexByCode[$code][] = $row;
                }
                $ean = $this->normalizeEan($row['eanCodeAsText'] ?? ($row['eanCode'] ?? null));
                if ($ean !== null) {
                    $erpIndexByEan[$ean][] = $row;
                }
            }

            foreach (array_keys($unmatched) as $k) {
                $t = $targets[$k];

                $found = null;

                if (!empty($t['articleNumber']) && isset($erpIndexByCode[$t['articleNumber']])) {
                    $found = $erpIndexByCode[$t['articleNumber']][0];
                } elseif (!empty($t['ean']) && isset($erpIndexByEan[$t['ean']])) {
                    $found = $erpIndexByEan[$t['ean']][0];
                }

                if ($found !== null) {
                    $matches[$k] = [
                        'kms' => $t['raw'],
                        'erp' => $found,
                        'match' => [
                            'by' => (!empty($t['articleNumber']) && (($found['productCode'] ?? '') === $t['articleNumber'])) ? 'productCode' : 'ean',
                            'erp_offset' => $offset,
                            'erp_limit' => $erpScanLimit,
                        ],
                    ];
                    unset($unmatched[$k]);
                }
            }

            $this->line(sprintf(
                'Scanned offset=%d (unmatched=%d, matched=%d)',
                $offset,
                count($unmatched),
                count($matches)
            ));
        }

        $result = [
            'correlationId' => $cid,
            'kms' => [
                'offset' => $kmsOffset,
                'limit' => $kmsLimit,
                'dump' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $kmsDumpPath),
                'count' => count($kmsProducts),
            ],
            'erp' => [
                'startOffset' => $erpStartOffset,
                'scanLimit' => $erpScanLimit,
                'maxOffset' => $erpMaxOffset,
                'first5Included' => $includeErpFirst5,
            ],
            'matches' => array_values($matches),
            'unmatched' => array_values(array_map(fn($k) => $targets[$k]['raw'], array_keys($unmatched))),
            'erpFirst5' => $erpFirst5,
        ];

        if ($doDump) {
            $outDir = storage_path('app/compare_dump');
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0777, true);
            }
            $outFile = $outDir . DIRECTORY_SEPARATOR . 'kms_erp_products_compare_' . date('Ymd_His') . '.json';
            file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Dumped to ' . str_replace(base_path().DIRECTORY_SEPARATOR, '', $outFile));
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function normalizeKmsProducts(array $kmsRaw): array
    {
        // KMS getProducts seems to return an object keyed by productId.
        $out = [];
        foreach ($kmsRaw as $key => $p) {
            if (!is_array($p)) continue;

            $article = (string)($p['articleNumber'] ?? $p['article_number'] ?? '');
            $ean = $this->normalizeEan($p['ean'] ?? $p['eanCodeAsText'] ?? null);

            $out[(string)$key] = [
                'articleNumber' => $article !== '' ? $article : null,
                'ean' => $ean,
                'raw' => $p,
            ];
        }
        return $out;
    }

    private function normalizeEan($value): ?string
    {
        if ($value === null) return null;
        $s = trim((string)$value);
        if ($s === '' || $s === '0') return null;
        // remove non-digits
        $digits = preg_replace('/\D+/', '', $s);
        if ($digits === '') return null;
        // keep as-is; do not strip leading zeros (ERP uses eanCodeAsText with leading zeros)
        return $digits;
    }
}
