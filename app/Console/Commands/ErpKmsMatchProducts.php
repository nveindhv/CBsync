<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fetch N products from KMS and find those products in ERP by scanning ERP products list.
 *
 * - KMS fetch uses POST kms/product/getProducts (same as your working kms:get:products command).
 * - ERP scan uses GET {ERP_BASE_URL}{ERP_API_BASE_PATH}/products?offset=&limit= (same as erp:get:products).
 *
 * Dumps JSON to storage/app/compare.
 */
class ErpKmsMatchProducts extends Command
{
    protected $signature = 'compare:kms-erp:products
        {--kms-limit=5}
        {--kms-offset=0}
        {--kms-articleNumber=}
        {--erp-start-offset=0}
        {--erp-scan-limit=200}
        {--erp-max-offset=120000}
        {--erp-first5}
        {--dump}
        {--dry-run}';

    protected $description = 'Compare KMS products with ERP products by scanning and matching on articleNumber/productCode and EAN';

    public function handle(): int
    {
        $correlationId = (string) Str::uuid();

        $kmsLimit = (int) $this->option('kms-limit');
        $kmsOffset = (int) $this->option('kms-offset');
        $kmsArticle = $this->option('kms-articleNumber');

        $erpStart = (int) $this->option('erp-start-offset');
        $erpLimit = (int) $this->option('erp-scan-limit');
        $erpMax = (int) $this->option('erp-max-offset');

        $this->info("CorrelationId: {$correlationId}");
        $this->line("Fetching KMS products (offset={$kmsOffset}, limit={$kmsLimit}" . ($kmsArticle ? ", articleNumber={$kmsArticle}" : '') . ")...");

        $kmsProductsRaw = $this->fetchKmsProducts($kmsArticle, $kmsOffset, $kmsLimit, $correlationId);
        $kmsProducts = $this->normalizeKmsProducts($kmsProductsRaw);

        if (count($kmsProducts) === 0) {
            $this->error('No products returned from KMS.');
            return self::FAILURE;
        }

        $this->info('KMS products loaded: ' . count($kmsProducts));

        $wantByArticle = [];
        $wantByEan = [];

        foreach ($kmsProducts as $p) {
            $article = (string) ($p['articleNumber'] ?? $p['article_number'] ?? '');
            $ean = (string) ($p['ean'] ?? $p['eanCode'] ?? '');

            if ($article !== '') {
                $wantByArticle[$article] = true;
            }
            if ($ean !== '') {
                $wantByEan[$this->normalizeEan($ean)] = true;
            }
        }

        $erpFirst5 = null;
        if ((bool) $this->option('erp-first5')) {
            $this->line('Fetching ERP first 5 products (offset=0, limit=5)...');
            if (!$this->option('dry-run')) {
                $erpFirst5 = $this->fetchErpProducts(0, 5, $correlationId);
            }
        }

        $this->line("Scanning ERP products from offset={$erpStart} to {$erpMax} (limit={$erpLimit})...");

        $found = ['byArticle' => [], 'byEan' => []];
        $scannedPages = 0;
        $scannedItems = 0;

        if (!$this->option('dry-run')) {
            for ($offset = $erpStart; $offset <= $erpMax; $offset += $erpLimit) {
                $scannedPages++;
                $rows = $this->fetchErpProducts($offset, $erpLimit, $correlationId);
                $scannedItems += count($rows);

                foreach ($rows as $row) {
                    $productCode = (string) ($row['productCode'] ?? '');
                    $eanText = (string) ($row['eanCodeAsText'] ?? $row['eanCode'] ?? '');
                    $eanNorm = $eanText !== '' ? $this->normalizeEan($eanText) : '';

                    if ($productCode !== '' && isset($wantByArticle[$productCode]) && !isset($found['byArticle'][$productCode])) {
                        $found['byArticle'][$productCode] = $row;
                    }
                    if ($eanNorm !== '' && isset($wantByEan[$eanNorm]) && !isset($found['byEan'][$eanNorm])) {
                        $found['byEan'][$eanNorm] = $row;
                    }
                }

                $this->line("- offset={$offset}: got=" . count($rows) . " | found(article=" . count($found['byArticle']) . ", ean=" . count($found['byEan']) . ")");

                $needArticle = count($wantByArticle);
                $needEan = count($wantByEan);

                $articleOk = ($needArticle === 0) || (count($found['byArticle']) >= $needArticle);
                $eanOk = ($needEan === 0) || (count($found['byEan']) >= $needEan);

                if ($articleOk && $eanOk) {
                    $this->info('All requested products found in ERP.');
                    break;
                }

                if (count($rows) < $erpLimit) {
                    $this->warn('ERP returned less than limit; reached end-of-list.');
                    break;
                }
            }
        }

        $out = [
            'meta' => [
                'correlationId' => $correlationId,
                'kms' => [
                    'offset' => $kmsOffset,
                    'limit' => $kmsLimit,
                    'articleNumber' => $kmsArticle,
                ],
                'erp' => [
                    'startOffset' => $erpStart,
                    'scanLimit' => $erpLimit,
                    'maxOffset' => $erpMax,
                    'pagesScanned' => $scannedPages,
                    'itemsScanned' => $scannedItems,
                ],
                'generatedAt' => now()->toDateTimeString(),
            ],
            'kmsProductsRaw' => $kmsProductsRaw,
            'kmsProducts' => $kmsProducts,
            'erpFirst5' => $erpFirst5,
            'erpMatches' => $found,
        ];

        $this->info("Scan done. Pages={$scannedPages}, items={$scannedItems}");

        if ((bool) $this->option('dump') && !$this->option('dry-run')) {
            $dir = storage_path('app/compare');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . '/kms_erp_products_' . now()->format('Ymd_His') . '.json';
            file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('Dumped: ' . $file);
        }

        return self::SUCCESS;
    }

    private function fetchKmsProducts(?string $articleNumber, int $offset, int $limit, string $correlationId): array
    {
        $baseUrl = (string) (config('services.kms.base_url') ?? config('kms.base_url') ?? env('KMS_BASE_URL', 'https://www.twensokms.nl'));
        $namespace = (string) (config('services.kms.namespace') ?? config('kms.namespace') ?? env('KMS_NAMESPACE', ''));

        $baseUrl = rtrim($baseUrl, '/');

        if ($baseUrl === '') {
            throw new \RuntimeException('KMS base URL missing. Set KMS_BASE_URL or services.kms.base_url');
        }
        if ($namespace === '') {
            throw new \RuntimeException('KMS namespace missing. Set KMS_NAMESPACE or services.kms.namespace');
        }

        $token = app(\App\Services\TokenService::class)->getToken();

        $url = $baseUrl . '/rest/' . $namespace . '/kms/product/getProducts';

        $payload = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'articleNumber' => $articleNumber,
        ], fn($v) => $v !== null && $v !== '');

        $resp = Http::timeout(90)->acceptJson()->withHeaders([
            'access_token' => $token,
            'X-Correlation-Id' => $correlationId,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        if (!$resp->ok()) {
            throw new \RuntimeException('KMS getProducts failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json() ?? [];
    }

    private function fetchErpProducts(int $offset, int $limit, string $correlationId): array
    {
        $base = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $path = '/' . ltrim((string) env('ERP_API_BASE_PATH', ''), '/');
        $url = $base . $path . '/products';

        if ($base === '') {
            throw new \RuntimeException('ERP_BASE_URL missing in .env');
        }

        $user = (string) env('ERP_USER', '');
        $pass = (string) env('ERP_PASS', '');

        $headers = [
            'Accept' => 'application/json',
            'X-Correlation-Id' => $correlationId,
        ];

        if ($admin = env('ERP_ADMIN')) {
            $headers['adminCode'] = $admin;
            $headers['AdminCode'] = $admin;
            $headers['X-Admin'] = $admin;
        }

        $resp = Http::withBasicAuth($user, $pass)
            ->withHeaders($headers)
            ->get($url, ['offset' => $offset, 'limit' => $limit]);

        if (!$resp->ok()) {
            throw new \RuntimeException('ERP request failed: HTTP ' . $resp->status() . ' ' . $resp->body());
        }

        return $resp->json() ?? [];
    }

    private function normalizeKmsProducts(array $raw): array
    {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            return $raw;
        }
        $values = array_values($raw);
        return is_array($values) ? $values : [];
    }

    private function normalizeEan(string $ean): string
    {
        $digits = preg_replace('/\D+/', '', $ean) ?? '';
        return ltrim($digits, '0');
    }
}
