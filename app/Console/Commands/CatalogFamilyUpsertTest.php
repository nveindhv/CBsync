<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CatalogFamilyUpsertTest extends Command
{
    protected $signature = 'catalog:family:upsert-test
        {family : Family prefix (like 50206042025)}
        {--family-length=11 : Prefix length}
        {--variants=3 : How many variants to test (first N from found list)}
        {--offset=0 : ERP offset to search (set to where you already saw this family)}
        {--limit=2000 : ERP scan limit for collecting variants}
        {--page-size=200 : ERP page size}
        {--dry-run : Do not call createUpdate}
        {--debug : Debug output}';

    protected $description = 'Collect variants for a family from ERP pages, then run KMS upsert-test on the first N variants.';

    public function handle()
    {
        $family = (string) $this->argument('family');
        $familyLen = max(1, (int) $this->option('family-length'));
        $variantsToTest = max(1, (int) $this->option('variants'));
        $offset = max(0, (int) $this->option('offset'));
        $limit = max(1, (int) $this->option('limit'));
        $pageSize = max(1, (int) $this->option('page-size'));
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');

        $this->info("=== FAMILY UPSERT TEST ===");
        $this->line("family={$family} familyLen={$familyLen} variants={$variantsToTest} offset={$offset} limit={$limit}");

        $erpBaseUrl = rtrim((string) env('ERP_BASE_URL', ''), '/');
        $erpApiBasePath = '/' . trim((string) env('ERP_API_BASE_PATH', ''), '/');
        $erpAdmin = trim((string) env('ERP_ADMIN', '01'), '/');

        $erpUser = (string) env('ERP_USER', '');
        $erpPass = (string) env('ERP_PASS', '');

        if ($erpBaseUrl === '' || trim($erpApiBasePath, '/') === '') {
            $this->error('Missing ERP_BASE_URL or ERP_API_BASE_PATH in .env');
            return 1;
        }

        $urlProducts = $erpBaseUrl . $erpApiBasePath . '/' . $erpAdmin . '/products';

        $httpErp = Http::timeout(90)
            ->withOptions(['verify' => false])
            ->acceptJson()
            ->when($erpUser !== '' || $erpPass !== '', fn ($h) => $h->withBasicAuth($erpUser, $erpPass));

        $variants = [];
        $scanned = 0;
        $page = 0;

        while ($scanned < $limit) {
            $pageOffset = $offset + ($page * $pageSize);
            $remaining = $limit - $scanned;
            $take = min($pageSize, $remaining);

            $this->line("[ERP_PAGE] offset={$pageOffset} limit={$take}");

            $resp = $httpErp->get($urlProducts, ['offset' => $pageOffset, 'limit' => $take]);
            if (!$resp->ok()) {
                $this->error('[ERP] FAIL status='.$resp->status().' body='.substr($resp->body(), 0, 300));
                return 1;
            }

            $items = $resp->json();
            if (!is_array($items) || count($items) === 0) break;

            foreach ($items as $p) {
                if (!is_array($p)) continue;
                $code = (string) ($p['productCode'] ?? '');
                if ($code === '') continue;

                $fam = substr($code, 0, $familyLen);
                if ($fam === $family) $variants[$code] = true;

                $scanned++;
                if ($scanned >= $limit) break;
            }

            if (count($items) < $take) break;
            $page++;
        }

        $variantList = array_keys($variants);
        sort($variantList);

        $this->line("ERP_variants_found=" . count($variantList));
        if (count($variantList) === 0) {
            $this->warn("No variants found in this ERP slice. Try another --offset/--limit.");
            return 0;
        }

        $toTest = array_slice($variantList, 0, $variantsToTest);
        $this->line("Testing: " . implode(', ', $toTest));

        foreach ($toTest as $v) {
            $this->call('catalog:kms:upsert-test', [
                'articleNumber' => $v,
                '--dry-run' => $dryRun,
                '--debug' => $debug,
                '--brand' => 'TESTBRAND',
                '--unit' => 'STK',
                '--color' => 'test',
                '--price' => 12.34,
            ]);
            $this->line('');
        }

        return 0;
    }
}
