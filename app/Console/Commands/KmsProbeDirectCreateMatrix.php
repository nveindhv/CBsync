<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KmsProbeDirectCreateMatrix extends Command
{
    protected $signature = 'kms:probe:direct-create-matrix
        {family9 : 9-cijferige familie/type_number voor een schone testfamilie}
        {--name= : Basisnaam voor producten}
        {--brand= : Merk}
        {--unit=STK : Eenheid}
        {--color-a=navy : Kleur voor child A}
        {--color-b=black : Kleur voor child B}
        {--size-a=44 : Maat voor child A}
        {--size-b=46 : Maat voor child B}
        {--article-a= : Override child A artikelnummer}
        {--article-b= : Override child B artikelnummer}
        {--ean-a= : Override child A EAN}
        {--ean-b= : Override child B EAN}
        {--ean-prefix=899 : Prefix voor synthetische EANs}
        {--after-wait=0 : Seconden wachten tussen createUpdate en after lookup}
        {--live : Doe echte createUpdate calls}
        {--write-json : Schrijf rapport naar storage/app/private/kms_scan/live_family_probes}
        {--debug : Toon payloads en lookup details}';

    protected $description = 'Probe directe product-creatie volgens createUpdate docs: name meesturen voor nieuwe child, type_number optioneel/aan te raden, zonder parent-first aanname.';

    public function handle(KmsClient $kms): int
    {
        $family9 = trim((string) $this->argument('family9'));
        if (! preg_match('/^\d{9}$/', $family9)) {
            $this->error('family9 moet exact 9 cijfers zijn.');
            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $debug = (bool) $this->option('debug');
        $afterWait = max(0, (int) $this->option('after-wait'));

        $nameBase = trim((string) ($this->option('name') ?: ('TEST DIRECT CREATE ' . $family9)));
        $brand = trim((string) ($this->option('brand') ?: 'TESTBRAND'));
        $unit = trim((string) ($this->option('unit') ?: 'STK'));
        $colorA = trim((string) ($this->option('color-a') ?: 'navy'));
        $colorB = trim((string) ($this->option('color-b') ?: 'black'));
        $sizeA = trim((string) ($this->option('size-a') ?: '44'));
        $sizeB = trim((string) ($this->option('size-b') ?: '46'));
        $eanPrefix = preg_replace('/\D+/', '', (string) ($this->option('ean-prefix') ?: '899')) ?: '899';

        $articleA = trim((string) ($this->option('article-a') ?: ($family9 . '005' . str_pad($sizeA, 3, '0', STR_PAD_LEFT))));
        $articleB = trim((string) ($this->option('article-b') ?: ($family9 . '008' . str_pad($sizeB, 3, '0', STR_PAD_LEFT))));
        $eanA = trim((string) ($this->option('ean-a') ?: $this->makeEan($eanPrefix, $articleA)));
        $eanB = trim((string) ($this->option('ean-b') ?: $this->makeEan($eanPrefix, $articleB)));

        $this->line('=== KMS DIRECT CREATE MATRIX ===');
        $this->line('Mode        : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Family9     : ' . $family9);
        $this->line('Child A     : ' . $articleA . ' / EAN ' . $eanA);
        $this->line('Child B     : ' . $articleB . ' / EAN ' . $eanB);
        $this->line('After wait  : ' . $afterWait . 's');
        $this->line('Principle   : docs zeggen: name meesturen => nieuw product mag worden toegevoegd; type_number aan te raden.');

        $children = [
            'A' => [
                'article' => $articleA,
                'ean' => $eanA,
                'name' => trim($nameBase . ' ' . $colorA . ' ' . $sizeA),
                'brand' => $brand,
                'unit' => $unit,
                'color' => $colorA,
                'size' => $sizeA,
            ],
            'B' => [
                'article' => $articleB,
                'ean' => $eanB,
                'name' => trim($nameBase . ' ' . $colorB . ' ' . $sizeB),
                'brand' => $brand,
                'unit' => $unit,
                'color' => $colorB,
                'size' => $sizeB,
            ],
        ];

        $scenarios = [
            'child_a_name_only' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                    ]),
                ],
            ],
            'child_a_name_plus_type9' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                        'type_number' => $family9,
                    ]),
                ],
            ],
            'child_a_name_brand_unit_type9' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'type_number' => $family9,
                    ]),
                ],
            ],
            'child_a_full_recommended' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'color' => true,
                        'size' => true,
                        'type_number' => $family9,
                        'type_name' => $nameBase,
                        'ean' => true,
                        'price' => 12.34,
                        'purchase_price' => 0.0,
                    ]),
                ],
            ],
            'two_children_same_type9' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'color' => true,
                        'size' => true,
                        'type_number' => $family9,
                        'type_name' => $nameBase,
                        'ean' => true,
                    ]),
                    $this->payloadFor($children['B'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'color' => true,
                        'size' => true,
                        'type_number' => $family9,
                        'type_name' => $nameBase,
                        'ean' => true,
                    ]),
                ],
            ],
            'two_children_same_type9_with_prices' => [
                'steps' => [
                    $this->payloadFor($children['A'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'color' => true,
                        'size' => true,
                        'type_number' => $family9,
                        'type_name' => $nameBase,
                        'ean' => true,
                        'price' => 12.34,
                        'purchase_price' => 0.0,
                    ]),
                    $this->payloadFor($children['B'], [
                        'name' => true,
                        'brand' => true,
                        'unit' => true,
                        'color' => true,
                        'size' => true,
                        'type_number' => $family9,
                        'type_name' => $nameBase,
                        'ean' => true,
                        'price' => 12.34,
                        'purchase_price' => 0.0,
                    ]),
                ],
            ],
        ];

        $report = [
            'family9' => $family9,
            'mode' => $live ? 'live' : 'dry-run',
            'generated_at' => now()->toIso8601String(),
            'children' => $children,
            'scenarios' => [],
            'first_visible' => null,
        ];

        foreach ($scenarios as $label => $scenario) {
            $this->newLine();
            $this->line('---------- [' . $label . '] ----------');

            $scenarioResult = [
                'steps' => [],
                'any_visible' => false,
            ];

            foreach ($scenario['steps'] as $stepIndex => $payload) {
                $product = $payload['products'][0] ?? [];
                $article = (string) ($product['article_number'] ?? '');
                $ean = (string) ($product['ean'] ?? '');

                $before = $this->lookup($kms, $article, $ean, $debug);
                if ($debug) {
                    $this->line('PAYLOAD ' . ($stepIndex + 1) . ' / ' . $article . ':');
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }

                $response = $live ? $this->safeCreateUpdate($kms, $payload) : ['dry_run' => true];
                if ($live) {
                    $this->line('createUpdate ' . $article . ': ' . json_encode($response, JSON_UNESCAPED_UNICODE));
                } else {
                    $this->line('dry-run ' . $article . ': prepared');
                }

                if ($afterWait > 0 && $live) {
                    sleep($afterWait);
                }

                $after = $this->lookup($kms, $article, $ean, $debug);
                $visible = $after['article_count'] > 0 || $after['ean_count'] > 0;

                $scenarioResult['steps'][] = [
                    'article' => $article,
                    'ean' => $ean,
                    'before' => $before,
                    'after' => $after,
                    'visible' => $visible,
                    'response' => $response,
                    'payload' => $payload,
                ];
                $scenarioResult['any_visible'] = $scenarioResult['any_visible'] || $visible;

                if ($visible && $report['first_visible'] === null) {
                    $report['first_visible'] = [
                        'scenario' => $label,
                        'article' => $article,
                    ];
                }

                $this->line(($visible ? 'VISIBLE' : 'NOT VISIBLE') . ' => ' . $article);
            }

            $report['scenarios'][$label] = $scenarioResult;
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $path = $dir . DIRECTORY_SEPARATOR . 'direct_create_matrix_' . $family9 . '_' . now()->format('Ymd_His') . '.json';
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->info('REPORT JSON : ' . $path);
        }

        $this->newLine();
        $this->line(json_encode([
            'family9' => $family9,
            'mode' => $live ? 'live' : 'dry-run',
            'any_success' => $report['first_visible'] !== null,
            'first_visible' => $report['first_visible'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function payloadFor(array $child, array $rules): array
    {
        $row = [
            'article_number' => $child['article'],
            'articleNumber' => $child['article'],
        ];

        if (! empty($rules['ean'])) {
            $row['ean'] = $child['ean'];
        }
        if (! empty($rules['name'])) {
            $row['name'] = $child['name'];
        }
        if (! empty($rules['brand'])) {
            $row['brand'] = $child['brand'];
        }
        if (! empty($rules['unit'])) {
            $row['unit'] = $child['unit'];
        }
        if (! empty($rules['color'])) {
            $row['color'] = $child['color'];
        }
        if (! empty($rules['size'])) {
            $row['size'] = $child['size'];
        }
        if (isset($rules['type_number']) && is_string($rules['type_number']) && $rules['type_number'] !== '') {
            $row['type_number'] = $rules['type_number'];
            $row['typeNumber'] = $rules['type_number'];
        }
        if (isset($rules['type_name']) && is_string($rules['type_name']) && $rules['type_name'] !== '') {
            $row['type_name'] = $rules['type_name'];
            $row['typeName'] = $rules['type_name'];
        }
        if (array_key_exists('price', $rules)) {
            $row['price'] = $rules['price'];
        }
        if (array_key_exists('purchase_price', $rules)) {
            $row['purchase_price'] = $rules['purchase_price'];
        }

        return ['products' => [$row]];
    }

    private function lookup(KmsClient $kms, string $article, string $ean, bool $debug = false): array
    {
        $articleRows = $this->safeNormalizeRows($kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ]));

        $eanRows = [];
        if ($ean !== '') {
            $eanRows = $this->safeNormalizeRows($kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 10,
                'ean' => $ean,
            ]));
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

    private function safeNormalizeRows($response): array
    {
        if (! is_array($response)) {
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

    private function safeCreateUpdate(KmsClient $kms, array $payload): array
    {
        try {
            $result = $kms->post('kms/product/createUpdate', $payload);
            return is_array($result) ? $result : ['raw' => $result];
        } catch (\Throwable $e) {
            return ['exception' => $e->getMessage()];
        }
    }

    private function makeEan(string $prefix, string $article): string
    {
        $digits = preg_replace('/\D+/', '', $prefix . $article);
        $base = substr(str_pad($digits, 12, '0'), 0, 12);
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (((($i + 1) % 2) === 0) ? 3 : 1) * (int) $digit;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $base . $check;
    }
}
