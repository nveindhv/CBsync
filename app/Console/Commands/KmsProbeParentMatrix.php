<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KmsProbeParentMatrix extends Command
{
    protected $signature = 'kms:probe:parent-matrix
        {family9? : 9-cijferig type_number / familie nummer. Laat leeg om automatisch een testfamilie te genereren.}
        {--name=TEST PARENT MATRIX : Basis productnaam / type_name}
        {--brand=TESTBRAND : Merk}
        {--unit=STK : Eenheid}
        {--color-a=navy : Eerste kleur}
        {--color-b=black : Tweede kleur}
        {--size-a=42 : Eerste maat}
        {--size-b=44 : Tweede maat}
        {--ean-prefix=899 : 3-cijferige prefix voor synthetische EANs}
        {--live : Voer createUpdate echt uit}
        {--write-json : Schrijf rapport naar storage/app/private/kms_scan/live_family_probes}
        {--debug : Toon payloads en lookup details}';

    protected $description = 'Dril gericht parent/basis create hypotheses met een schone synthetische familie zodat zichtbaar wordt welk parent-model het KMS accepteert.';

    public function handle(KmsClient $kms): int
    {
        $family9 = (string) ($this->argument('family9') ?: $this->generateFamily9());
        if (!preg_match('/^\d{9}$/', $family9)) {
            $this->error('family9 moet exact 9 cijfers zijn.');
            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        $brand = (string) $this->option('brand');
        $unit = (string) $this->option('unit');
        $colorA = (string) $this->option('color-a');
        $colorB = (string) $this->option('color-b');
        $sizeA = (string) $this->option('size-a');
        $sizeB = (string) $this->option('size-b');
        $eanPrefix = preg_replace('/\D+/', '', (string) $this->option('ean-prefix')) ?: '899';
        $live = (bool) $this->option('live');
        $debug = (bool) $this->option('debug');

        $parent9 = $family9;
        $basisA = $family9 . '001';
        $basisB = $family9 . '030';
        $variantA1 = $basisA . str_pad($sizeA, 3, '0', STR_PAD_LEFT);
        $variantA2 = $basisA . str_pad($sizeB, 3, '0', STR_PAD_LEFT);
        $variantB1 = $basisB . str_pad($sizeA, 3, '0', STR_PAD_LEFT);
        $variantB2 = $basisB . str_pad($sizeB, 3, '0', STR_PAD_LEFT);

        $eanMap = [
            $basisA => $this->makeEan($eanPrefix, $basisA),
            $basisB => $this->makeEan($eanPrefix, $basisB),
            $variantA1 => $this->makeEan($eanPrefix, $variantA1),
            $variantA2 => $this->makeEan($eanPrefix, $variantA2),
            $variantB1 => $this->makeEan($eanPrefix, $variantB1),
            $variantB2 => $this->makeEan($eanPrefix, $variantB2),
        ];

        $this->line('=== KMS PARENT MATRIX PROBE ===');
        $this->line('Mode        : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Type(9)     : ' . $family9);
        $this->line('Parent(9)   : ' . $parent9);
        $this->line('Basis(12) A : ' . $basisA . ' (' . $colorA . ')');
        $this->line('Basis(12) B : ' . $basisB . ' (' . $colorB . ')');
        $this->line('Variant A1  : ' . $variantA1 . ' / EAN ' . $eanMap[$variantA1]);
        $this->line('Variant A2  : ' . $variantA2 . ' / EAN ' . $eanMap[$variantA2]);
        $this->line('Variant B1  : ' . $variantB1 . ' / EAN ' . $eanMap[$variantB1]);
        $this->line('Variant B2  : ' . $variantB2 . ' / EAN ' . $eanMap[$variantB2]);

        $scenarios = [
            'parent9_only' => [
                $this->product($parent9, '', $name, $brand, $unit, null, null, $family9, ''),
            ],
            'basis12_only' => [
                $this->product($basisA, $eanMap[$basisA], $name . ' ' . $colorA, $brand, $unit, $colorA, null, $family9, $name),
            ],
            'basis12_then_variants' => [
                $this->product($basisA, $eanMap[$basisA], $name . ' ' . $colorA, $brand, $unit, $colorA, null, $family9, $name),
                $this->product($variantA1, $eanMap[$variantA1], $name . ' ' . $colorA . ' ' . $sizeA, $brand, $unit, $colorA, $sizeA, $family9, $name),
                $this->product($variantA2, $eanMap[$variantA2], $name . ' ' . $colorA . ' ' . $sizeB, $brand, $unit, $colorA, $sizeB, $family9, $name),
            ],
            'parent9_plus_basis12_plus_variants' => [
                $this->product($parent9, '', $name, $brand, $unit, null, null, $family9, ''),
                $this->product($basisA, $eanMap[$basisA], $name . ' ' . $colorA, $brand, $unit, $colorA, null, $family9, $name),
                $this->product($variantA1, $eanMap[$variantA1], $name . ' ' . $colorA . ' ' . $sizeA, $brand, $unit, $colorA, $sizeA, $family9, $name),
                $this->product($variantA2, $eanMap[$variantA2], $name . ' ' . $colorA . ' ' . $sizeB, $brand, $unit, $colorA, $sizeB, $family9, $name),
            ],
            'two_basis12_colors_then_variants' => [
                $this->product($basisA, $eanMap[$basisA], $name . ' ' . $colorA, $brand, $unit, $colorA, null, $family9, $name),
                $this->product($basisB, $eanMap[$basisB], $name . ' ' . $colorB, $brand, $unit, $colorB, null, $family9, $name),
                $this->product($variantA1, $eanMap[$variantA1], $name . ' ' . $colorA . ' ' . $sizeA, $brand, $unit, $colorA, $sizeA, $family9, $name),
                $this->product($variantA2, $eanMap[$variantA2], $name . ' ' . $colorA . ' ' . $sizeB, $brand, $unit, $colorA, $sizeB, $family9, $name),
                $this->product($variantB1, $eanMap[$variantB1], $name . ' ' . $colorB . ' ' . $sizeA, $brand, $unit, $colorB, $sizeA, $family9, $name),
                $this->product($variantB2, $eanMap[$variantB2], $name . ' ' . $colorB . ' ' . $sizeB, $brand, $unit, $colorB, $sizeB, $family9, $name),
            ],
        ];

        $report = [
            'family9' => $family9,
            'mode' => $live ? 'live' : 'dry-run',
            'generated' => now()->toIso8601String(),
            'scenarios' => [],
            'first_visible' => null,
        ];

        foreach ($scenarios as $key => $products) {
            $this->newLine();
            $this->line('---------- [' . $key . '] ----------');
            $scenarioResult = [
                'products' => [],
                'any_visible' => false,
            ];

            foreach ($products as $payload) {
                $article = (string) $payload['products'][0]['article_number'];
                $ean = (string) ($payload['products'][0]['ean'] ?? '');

                $before = $this->lookup($kms, $article, $ean, $debug);
                if ($debug) {
                    $this->line('PAYLOAD ' . $article . ':');
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }

                $createResponse = null;
                if ($live) {
                    $createResponse = $this->safeCreateUpdate($kms, $payload);
                    $this->line('createUpdate ' . $article . ': ' . json_encode($createResponse, JSON_UNESCAPED_UNICODE));
                } else {
                    $this->line('dry-run ' . $article . ': prepared');
                }

                $after = $this->lookup($kms, $article, $ean, $debug);
                $visible = $after['article_count'] > 0 || $after['ean_count'] > 0;
                $scenarioResult['products'][] = [
                    'article' => $article,
                    'ean' => $ean,
                    'before' => $before,
                    'after' => $after,
                    'visible' => $visible,
                    'create_response' => $createResponse,
                    'payload' => $payload,
                ];
                $scenarioResult['any_visible'] = $scenarioResult['any_visible'] || $visible;
                if ($visible && $report['first_visible'] === null) {
                    $report['first_visible'] = [
                        'scenario' => $key,
                        'article' => $article,
                    ];
                }

                $this->line(($visible ? 'VISIBLE' : 'NOT VISIBLE') . ' => ' . $article);
            }

            $report['scenarios'][$key] = $scenarioResult;
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $path = $dir . DIRECTORY_SEPARATOR . 'parent_matrix_' . $family9 . '_' . now()->format('Ymd_His') . '.json';
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->info('REPORT JSON : ' . $path);
        }

        $this->newLine();
        $this->line(json_encode([
            'family9' => $family9,
            'any_success' => $report['first_visible'] !== null,
            'first_visible' => $report['first_visible'],
            'dry_run' => !$live,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function product(
        string $article,
        string $ean,
        string $name,
        string $brand,
        string $unit,
        ?string $color,
        ?string $size,
        string $typeNumber,
        string $typeName
    ): array {
        $row = [
            'article_number' => $article,
            'articleNumber' => $article,
            'name' => $name,
            'brand' => $brand,
            'unit' => $unit,
            'price' => 12.34,
            'purchase_price' => 0,
            'type_number' => $typeNumber,
            'typeNumber' => $typeNumber,
        ];

        if ($ean !== '') {
            $row['ean'] = $ean;
        }
        if ($typeName !== '') {
            $row['type_name'] = $typeName;
            $row['typeName'] = $typeName;
        }
        if ($color !== null) {
            $row['color'] = $color;
        }
        if ($size !== null) {
            $row['size'] = (string) $size;
        }

        return ['products' => [$row]];
    }

    private function lookup(KmsClient $kms, string $article, string $ean, bool $debug = false): array
    {
        $articleRows = $this->safeGetProducts($kms, ['offset' => 0, 'limit' => 10, 'articleNumber' => $article]);
        $eanRows = [];
        if ($ean !== '') {
            $eanRows = $this->safeGetProducts($kms, ['offset' => 0, 'limit' => 10, 'ean' => $ean]);
        }

        if ($debug) {
            $this->line('lookup article=' . $article . ' count=' . count($articleRows));
            if ($ean !== '') {
                $this->line('lookup ean=' . $ean . ' count=' . count($eanRows));
            }
        }

        return [
            'article_count' => count($articleRows),
            'ean_count' => count($eanRows),
            'article_first' => $articleRows[0] ?? null,
            'ean_first' => $eanRows[0] ?? null,
        ];
    }

    private function safeGetProducts(KmsClient $kms, array $payload): array
    {
        try {
            $result = $kms->post('kms/product/getProducts', $payload);
            return is_array($result) ? array_values($result) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function safeCreateUpdate(KmsClient $kms, array $payload): array
    {
        try {
            $result = $kms->post('kms/product/createUpdate', $payload);
            return is_array($result) ? $result : ['raw' => $result];
        } catch (\Throwable $e) {
            return ['exception' => $e->getMessage()];
        }
    }

    private function generateFamily9(): string
    {
        return '999' . substr((string) time(), -6);
    }

    private function makeEan(string $prefix, string $article): string
    {
        $digits = preg_replace('/\D+/', '', $prefix . $article);
        $base = substr(str_pad($digits, 12, '0'), 0, 12);
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += ((($i + 1) % 2) === 0 ? 3 : 1) * (int) $digit;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $base . $check;
    }
}
