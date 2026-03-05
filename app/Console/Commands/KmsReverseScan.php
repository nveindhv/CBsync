<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Scan a range of KMS products (offset/limit paging) and reverse engineer
 * update capabilities per derived family (type_number = prefix of articleNumber).
 *
 * Goal:
 *  - Discover which "families"/suppliers are updateable without type_*
 *  - Which require type_number/type_name
 *  - Which appear read-only (createUpdate success but no effect)
 *
 * This command is intentionally defensive: it tries multiple method names on KmsClient
 * because CBsync variants have slightly different client APIs.
 */
class KmsReverseScan extends Command
{
    protected $signature = 'kms:reverse:scan
        {--offset=0 : KMS getProducts offset to start from}
        {--scan=10000 : How many products to scan from KMS (not how many to mutate)}
        {--page-size=200 : Page size for KMS getProducts paging}
        {--createupdate-path=kms/product/createUpdate : Primary createUpdate endpoint path}
        {--createupdate-path-alt=kms/product/createUpdateProducts : Alternate createUpdate endpoint path (used if 404)}
        {--family-len=11 : Derive type_number from first N chars of articleNumber}
        {--max-families=250 : Max unique families to probe (mutate+verify). Increase with care}
        {--delta=0.11 : Price delta to use for probing}
        {--fields=price : Probe field set (currently only price is used as the primary capability signal)}
        {--no-revert : Do not revert changes (NOT recommended)}
        {--debug : Verbose output}
    ';

    protected $description = 'Scan KMS product range and reverse engineer updateability per family (type_number/type_name requirement).';

    private array $createUpdatePaths = [];

    public function handle(): int
    {
        $offset = (int)$this->option('offset');
        $scan = (int)$this->option('scan');
        $pageSize = (int)$this->option('page-size');
        $createPath = (string)$this->option('createupdate-path');
        $createAlt  = (string)$this->option('createupdate-path-alt');
        $this->createUpdatePaths = array_values(array_filter([$createPath, $createAlt]));
        $familyLen = (int)$this->option('family-len');
        $maxFamilies = (int)$this->option('max-families');
        $delta = (float)$this->option('delta');
        $debug = (bool)$this->option('debug');
        $doRevert = !(bool)$this->option('no-revert');

        $this->info('=== KMS REVERSE SCAN (v1.14) ===');
        $this->line("offset={$offset} scan={$scan} pageSize={$pageSize} familyLen={$familyLen} maxFamilies={$maxFamilies} delta={$delta} revert=" . ($doRevert ? 'YES' : 'NO'));

        $client = $this->resolveKmsClient();
        if (!$client) {
            $this->error('Could not resolve App\\Services\\Kms\\KmsClient from container.');
            return self::FAILURE;
        }

        $families = []; // type_number => ['sample'=>product]
        $seenProducts = 0;

        // 1) scan KMS product list to collect families
        $cursor = $offset;
        while ($seenProducts < $scan) {
            $limit = min($pageSize, $scan - $seenProducts);

            $list = $this->kmsGetProductsPage($client, $cursor, $limit, $debug);
            $count = is_array($list) ? count($list) : 0;

            if ($count === 0) {
                $this->warn("No more products returned at offset={$cursor}. Stopping scan.");
                break;
            }

            foreach ($list as $row) {
                $seenProducts++;
                $article = (string)Arr::get($row, 'articleNumber', Arr::get($row, 'article_number', ''));
                if ($article === '') continue;

                $typeNumber = substr($article, 0, $familyLen);
                if (!isset($families[$typeNumber])) {
                    $families[$typeNumber] = [
                        'type_number' => $typeNumber,
                        'sample' => $row,
                    ];
                    if (count($families) >= $maxFamilies) {
                        break 2;
                    }
                }
            }

            $cursor += $limit;
            if ($debug) $this->line("[SCAN] cursor={$cursor} seenProducts={$seenProducts} uniqueFamilies=" . count($families));
        }

        $this->info("Collected unique families for probing: " . count($families));
        if (count($families) === 0) {
            $this->warn('Nothing to probe.');
            return self::SUCCESS;
        }

        // 2) Probe each family with 2 attempts:
        //    A) rich payload WITHOUT type_*  (detect updateable-without-type)
        //    B) rich payload WITH type_*     (detect requires-type or read-only)
        $report = [
            'meta' => [
                'timestamp' => Carbon::now()->toIso8601String(),
                'offset' => $offset,
                'scan' => $scan,
                'page_size' => $pageSize,
                'family_len' => $familyLen,
                'max_families' => $maxFamilies,
                'delta' => $delta,
                'revert' => $doRevert,
            ],
            'summary' => [
                'updateable_without_type' => 0,
                'requires_type' => 0,
                'read_only_or_ignored' => 0,
                'errors' => 0,
            ],
            'families' => [],
        ];

        $i = 0;
        foreach ($families as $typeNumber => $info) {
            $i++;
            $sample = $info['sample'];
            $article = (string)Arr::get($sample, 'articleNumber', '');
            $ean = (string)Arr::get($sample, 'ean', '');
            $supplierId = Arr::get($sample, 'supplierId');
            $supplierName = Arr::get($sample, 'supplierName');

            $this->line("\n[PROBE {$i}/" . count($families) . "] type_number={$typeNumber} sample_article={$article} ean={$ean} supplier={$supplierName}");

            try {
                $before = $this->kmsGetByArticle($client, $article, 1, $debug);
                $beforeItem = $this->firstProduct($before);
                if (!$beforeItem) {
                    $this->warn("Skip: sample article not found in KMS at probe time: {$article}");
                    continue;
                }

                $beforePrice = (float)Arr::get($beforeItem, 'price', 0);
                $newPrice = $this->safePriceDelta($beforePrice, $delta);

                // Probe A: rich without type_*
                $resultA = $this->probePrice($client, $beforeItem, $article, $ean, $newPrice, $debug, $doRevert, false);

                // Probe B: rich with type_*
                $resultB = $this->probePrice($client, $beforeItem, $article, $ean, $newPrice, $debug, $doRevert, true, $typeNumber);

                $classification = 'read_only_or_ignored';
                if ($resultA['updated'] === true) {
                    $classification = 'updateable_without_type';
                } elseif ($resultA['updated'] === false && $resultB['updated'] === true) {
                    $classification = 'requires_type';
                }

                $report['summary'][$classification]++;

                $report['families'][$typeNumber] = [
                    'type_number' => $typeNumber,
                    'sample' => [
                        'id' => Arr::get($beforeItem, 'id'),
                        'articleNumber' => $article,
                        'ean' => $ean,
                        'name' => Arr::get($beforeItem, 'name'),
                        'supplierId' => $supplierId,
                        'supplierName' => $supplierName,
                        'unit' => Arr::get($beforeItem, 'unit'),
                        'brand' => Arr::get($beforeItem, 'brand'),
                        'color' => Arr::get($beforeItem, 'color'),
                        'size' => Arr::get($beforeItem, 'size'),
                        'price' => $beforePrice,
                    ],
                    'probe' => [
                        'without_type' => $resultA,
                        'with_type' => $resultB,
                    ],
                    'classification' => $classification,
                ];

                $this->info("=> {$classification} (A=" . ($resultA['updated'] ? 'UPDATED' : 'IGNORED') . " B=" . ($resultB['updated'] ? 'UPDATED' : 'IGNORED') . ")");
            } catch (\Throwable $e) {
                $report['summary']['errors']++;
                $this->error("Error probing family {$typeNumber}: " . $e->getMessage());
                if ($debug) $this->line($e->getTraceAsString());
            }
        }

        // 3) write report (storage/app + also project-root/kms_reverse_scan for convenience)
        $ts = Carbon::now()->format('Ymd_His');
        $jsonPath = "kms_reverse_scan/report_{$ts}.json";
        Storage::disk('local')->put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // also write a compact CSV-ish summary
        $lines = [];
        $lines[] = "type_number,classification,sample_article,ean,supplierName,updated_without_type,updated_with_type";
        foreach ($report['families'] as $f) {
            $lines[] = implode(',', [
                $this->csv($f['type_number']),
                $this->csv($f['classification']),
                $this->csv($f['sample']['articleNumber']),
                $this->csv($f['sample']['ean']),
                $this->csv((string)$f['sample']['supplierName']),
                $this->csv($f['probe']['without_type']['updated'] ? '1' : '0'),
                $this->csv($f['probe']['with_type']['updated'] ? '1' : '0'),
            ]);
        }
        $csvPath = "kms_reverse_scan/report_{$ts}.csv";
        Storage::disk('local')->put($csvPath, implode("\n", $lines));

        // Many users expect reports in project-root/kms_reverse_scan (not storage/app).
        // So we copy them there as well.
        try {
            $rootDir = base_path('kms_reverse_scan');
            if (!is_dir($rootDir)) {
                @mkdir($rootDir, 0777, true);
            }

            $jsonAbs = Storage::disk('local')->path($jsonPath);
            $csvAbs  = Storage::disk('local')->path($csvPath);

            @copy($jsonAbs, $rootDir . DIRECTORY_SEPARATOR . basename($jsonPath));
            @copy($csvAbs,  $rootDir . DIRECTORY_SEPARATOR . basename($csvPath));
        } catch (\Throwable $e) {
            // Non-fatal: storage/app still contains the report.
            if ($debug) {
                $this->warn('Could not copy reports to project root: ' . $e->getMessage());
            }
        }

        $this->info("\n=== DONE ===");
        $this->line("JSON: storage/app/{$jsonPath}");
        $this->line("CSV : storage/app/{$csvPath}");
        $this->line("JSON (project root): kms_reverse_scan/" . basename($jsonPath));
        $this->line("CSV  (project root): kms_reverse_scan/" . basename($csvPath));
        $this->line("Summary: " . json_encode($report['summary']));

        return self::SUCCESS;
    }

    private function resolveKmsClient()
    {
        $class = '\\App\\Services\\Kms\\KmsClient';
        if (!class_exists($class)) return null;
        return app($class);
    }


private function createUpdatePath(): string
{
    return $this->createUpdatePaths[0] ?? 'kms/product/createUpdate';
}

private function createUpdateAltPath(): ?string
{
    return $this->createUpdatePaths[1] ?? 'kms/product/createUpdateProducts';
}

    private function kmsCall($client, string $method, string $path, array $payload, bool $debug)
    {
        // try common client shapes
        if (method_exists($client, 'post')) {
            return $client->post($path, $payload, $debug);
        }
        if (method_exists($client, 'call')) {
            return $client->call($method, $path, $payload, $debug);
        }
        if (method_exists($client, 'request')) {
            return $client->request($method, $path, $payload, $debug);
        }
        if (method_exists($client, 'send')) {
            return $client->send($method, $path, $payload, $debug);
        }

        throw new \RuntimeException('Unsupported KmsClient API: expected post/call/request/send methods.');
    }

    /**
     * Call createUpdate against configured paths (primary then alternate on 404).
     *
     * @return array{0:mixed,1:string} [response, usedPath]
     */
    private function kmsCallCreateUpdate($client, array $payload, bool $debug): array
    {
        $paths = array_values(array_filter([
            $this->createUpdatePath(),
            $this->createUpdateAltPath(),
        ]));

        // Deduplicate while preserving order.
        $seen = [];
        $paths = array_values(array_filter($paths, function ($p) use (&$seen) {
            if (isset($seen[$p])) {
                return false;
            }
            $seen[$p] = true;
            return true;
        }));

        $last = null;
        foreach ($paths as $path) {
            try {
                $resp = $this->kmsCall($client, "POST", $path, $payload, $debug);
                return [$resp, $path];
            } catch (\Throwable $e) {
                $last = $e;
                $msg = $e->getMessage();

                // Only fall back on 404.
                if (str_contains($msg, "HTTP 404") || str_contains($msg, " 404:") || str_contains($msg, "\"status\":404")) {
                    if ($debug) {
                        $this->line("CREATEUPDATE path \"{$path}\" returned 404; trying next (if any)...");
                    }
                    continue;
                }
                throw $e;
            }
        }

        if ($last) {
            throw $last;
        }
        throw new \RuntimeException("No createUpdate paths configured.");
    }

    private function kmsGetProductsPage($client, int $offset, int $limit, bool $debug): array
    {
        $payload = ['offset' => $offset, 'limit' => $limit];
        $resp = $this->kmsCall($client, 'POST', 'kms/product/getProducts', $payload, $debug);

        // response shapes may vary: sometimes array keyed by id, sometimes list
        if (!is_array($resp)) return [];
        // if keyed by id, values are products
        $firstKey = array_key_first($resp);
        if ($firstKey !== null && is_string($firstKey) && ctype_digit($firstKey)) {
            return array_values($resp);
        }
        // if already list of products
        return array_values($resp);
    }

    private function kmsGetByArticle($client, string $articleNumber, int $limit, bool $debug): array
    {
        $payload = ['offset' => 0, 'limit' => $limit, 'articleNumber' => $articleNumber];
        $resp = $this->kmsCall($client, 'POST', 'kms/product/getProducts', $payload, $debug);
        return is_array($resp) ? $resp : [];
    }

    private function firstProduct(array $resp): ?array
    {
        if (empty($resp)) return null;
        $first = reset($resp);
        if (is_array($first)) return $first;
        return null;
    }

    private function safePriceDelta(float $before, float $delta): float
    {
        // avoid going negative and avoid rounding to same value
        $candidate = round($before + $delta, 2);
        if (abs($candidate - $before) < 0.001) {
            $candidate = round($before + 0.27, 2);
        }
        if ($candidate < 0.01) $candidate = 0.11;
        return $candidate;
    }

    private function probePrice($client, array $snapshot, string $article, string $ean, float $newPrice, bool $debug, bool $doRevert, bool $withType, ?string $typeNumberOverride = null): array
    {
        $beforePrice = (float)Arr::get($snapshot, 'price', 0);
        $typeNumber = $typeNumberOverride ?: substr($article, 0, 11);
        $typeName = "FAMILY {$typeNumber}";

        $base = [
            'article_number' => $article,
            'articleNumber'  => $article,
            'ean' => $ean,
            // rich context if available
            'unit' => Arr::get($snapshot, 'unit'),
            'brand' => Arr::get($snapshot, 'brand'),
            'color' => Arr::get($snapshot, 'color'),
            'size' => Arr::get($snapshot, 'size'),
            'price' => $newPrice,
        ];

        if ($withType) {
            $base['type_number'] = $typeNumber;
            $base['typeNumber'] = $typeNumber;
            $base['type_name'] = $typeName;
            $base['typeName'] = $typeName;
        }

        // remove nulls to reduce noise
        $product = array_filter($base, fn($v) => $v !== null && $v !== '');

        $payload = ['products' => [$product]];
        if ($debug) $this->line("CREATEUPDATE payload (withType=" . ($withType?'yes':'no') . "): " . json_encode($payload));

$resp = null;
$success = false;
$pathsTried = [];
foreach (array_filter([$this->createUpdatePath(), $this->createUpdateAltPath()]) as $p) {
    $pathsTried[] = $p;
    try {
        $resp = $this->kmsCall($client, 'POST', $p, $payload, $debug);
        $success = (bool)Arr::get($resp, 'success', false);
        break;
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'HTTP 404') || str_contains($msg, 'Not Found')) {
            if ($debug) $this->line("CREATEUPDATE path '{$p}' returned 404; trying next (if any)...");
            continue;
        }
        throw $e;
    }
}
if ($resp === null) {
    throw new \RuntimeException('All createUpdate paths failed (tried: ' . implode(', ', $pathsTried) . ')');
}


        $afterResp = $this->kmsGetByArticle($client, $article, 1, $debug);
        $afterItem = $this->firstProduct($afterResp);
        $afterPrice = $afterItem ? (float)Arr::get($afterItem, 'price', 0) : $beforePrice;

        $updated = abs($afterPrice - $newPrice) < 0.001;

        // revert if we changed it
        if ($updated && $doRevert) {
            $product['price'] = $beforePrice;
            $revertPayload = ['products' => [$product]];
            if ($debug) $this->line("REVERT payload (withType=" . ($withType?'yes':'no') . "): " . json_encode($revertPayload));
            $this->kmsCallCreateUpdate($client, $revertPayload, $debug);
        }

        return [
            'success' => $success,
            'updated' => $updated,
            'before_price' => $beforePrice,
            'attempt_price' => $newPrice,
            'after_price' => $afterPrice,
        ];
    }

    private function csv(string $v): string
    {
        $v = str_replace('"', '""', $v);
        return '"' . $v . '"';
    }
}
