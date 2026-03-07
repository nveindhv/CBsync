<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KmsProbeExistingFamilySibling extends Command
{
    protected $signature = 'kms:probe:existing-family-sibling
        {seedArticle : Existing visible KMS variant article, e.g. 100010001099420}
        {newArticle : New sibling article to try, e.g. 100010001099490}
        {--new-ean= : New unique EAN for the sibling}
        {--new-size= : New size for the sibling}
        {--family-article= : Visible family article from UI, e.g. 10001000109}
        {--basis12= : Basis article from scan/UI, e.g. 100010001099}
        {--type-number= : Override type_number for child}
        {--type-name= : Override type_name for child}
        {--brand= : Override brand}
        {--unit= : Override unit}
        {--color= : Override color}
        {--name= : Override product name}
        {--price=12.34 : Price to send}
        {--purchase-price=0 : Purchase price to send}
        {--live : Actually post createUpdate}
        {--write-json : Write report json}
        {--debug : Verbose logging}';

    protected $description = 'Probe the shortest real breakthrough: create a new sibling inside an already visible KMS family/basis structure.';

    public function handle(): int
    {
        $kms = app(\App\Services\Kms\KmsClient::class);

        $seedArticle = (string) $this->argument('seedArticle');
        $newArticle = (string) $this->argument('newArticle');
        $newEan = (string) ($this->option('new-ean') ?: '');
        $newSize = (string) ($this->option('new-size') ?: '');
        $familyArticle = (string) ($this->option('family-article') ?: substr($seedArticle, 0, 11));
        $basis12 = (string) ($this->option('basis12') ?: substr($seedArticle, 0, 12));
        $live = (bool) $this->option('live');
        $debug = (bool) $this->option('debug');

        $this->line('=== KMS EXISTING FAMILY SIBLING PROBE ===');
        $this->line('Mode         : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Seed article : ' . $seedArticle);
        $this->line('New article  : ' . $newArticle);
        $this->line('Family(11)   : ' . $familyArticle);
        $this->line('Basis(12)    : ' . $basis12);

        $seedRows = $this->lookupArticle($kms, $seedArticle, $debug);
        if (count($seedRows) < 1) {
            $this->error('Seed article not visible in KMS: ' . $seedArticle);
            return self::FAILURE;
        }

        $seed = $seedRows[0];
        $brand = (string) ($this->option('brand') ?: ($seed['brand'] ?? ''));
        $unit = (string) ($this->option('unit') ?: ($seed['unit'] ?? ''));
        $color = (string) ($this->option('color') ?: ($seed['color'] ?? ''));
        $name = (string) ($this->option('name') ?: ($seed['name'] ?? ''));
        $typeNumberOverride = (string) ($this->option('type-number') ?: '');
        $typeNameOverride = (string) ($this->option('type-name') ?: '');
        $price = (float) $this->option('price');
        $purchasePrice = (float) $this->option('purchase-price');

        $defaultTypeCandidates = array_values(array_unique(array_filter([
            $typeNumberOverride,
            substr($seedArticle, 0, 9),
            $familyArticle,
            $basis12,
        ])));

        $defaultTypeNameCandidates = array_values(array_unique(array_filter([
            $typeNameOverride,
            'FAMILY ' . $familyArticle,
            $name,
        ])));

        $scenarios = [
            'variant_only_type9_name' => [
                'pre' => [],
                'child' => [
                    'type_number' => substr($seedArticle, 0, 9),
                    'type_name' => $typeNameOverride ?: $name,
                ],
            ],
            'variant_only_family11_name' => [
                'pre' => [],
                'child' => [
                    'type_number' => $familyArticle,
                    'type_name' => $typeNameOverride ?: ('FAMILY ' . $familyArticle),
                ],
            ],
            'create_family11_then_variant' => [
                'pre' => [[
                    'label' => 'family11',
                    'payload' => [
                        'products' => [[
                            'article_number' => $familyArticle,
                            'articleNumber' => $familyArticle,
                            'name' => 'FAMILY ' . $familyArticle,
                            'brand' => $brand,
                            'unit' => $unit,
                            'price' => $price,
                            'purchase_price' => $purchasePrice,
                            'type_number' => substr($seedArticle, 0, 9),
                            'type_name' => 'FAMILY ' . $familyArticle,
                        ]],
                    ],
                ]],
                'child' => [
                    'type_number' => $familyArticle,
                    'type_name' => 'FAMILY ' . $familyArticle,
                ],
            ],
            'create_basis12_then_variant' => [
                'pre' => [[
                    'label' => 'basis12',
                    'payload' => [
                        'products' => [[
                            'article_number' => $basis12,
                            'articleNumber' => $basis12,
                            'name' => $name,
                            'brand' => $brand,
                            'unit' => $unit,
                            'price' => $price,
                            'purchase_price' => $purchasePrice,
                            'type_number' => $familyArticle,
                            'type_name' => 'FAMILY ' . $familyArticle,
                            'color' => $color,
                        ]],
                    ],
                ]],
                'child' => [
                    'type_number' => $familyArticle,
                    'type_name' => 'FAMILY ' . $familyArticle,
                ],
            ],
            'basis12_plus_seed_plus_new_sibling' => [
                'pre' => [[
                    'label' => 'basis12',
                    'payload' => [
                        'products' => [[
                            'article_number' => $basis12,
                            'articleNumber' => $basis12,
                            'name' => $name,
                            'brand' => $brand,
                            'unit' => $unit,
                            'price' => $price,
                            'purchase_price' => $purchasePrice,
                            'type_number' => $familyArticle,
                            'type_name' => 'FAMILY ' . $familyArticle,
                            'color' => $color,
                        ]],
                    ],
                ], [
                    'label' => 'seed_touch',
                    'payload' => [
                        'products' => [[
                            'article_number' => $seedArticle,
                            'articleNumber' => $seedArticle,
                            'ean' => (string) ($seed['ean'] ?? ''),
                            'name' => $name,
                            'brand' => $brand,
                            'unit' => $unit,
                            'price' => $price,
                            'purchase_price' => $purchasePrice,
                            'type_number' => $familyArticle,
                            'type_name' => 'FAMILY ' . $familyArticle,
                            'color' => (string) ($seed['color'] ?? $color),
                            'size' => (string) ($seed['size'] ?? ''),
                        ]],
                    ],
                ]],
                'child' => [
                    'type_number' => $familyArticle,
                    'type_name' => 'FAMILY ' . $familyArticle,
                ],
            ],
        ];

        $results = [
            'seed_article' => $seedArticle,
            'new_article' => $newArticle,
            'family_article' => $familyArticle,
            'basis12' => $basis12,
            'live' => $live,
            'scenarios' => [],
        ];

        foreach ($scenarios as $label => $scenario) {
            $this->newLine();
            $this->line('--- ' . $label . ' ---');

            $scenarioResult = [
                'pre' => [],
                'child' => null,
                'visible' => false,
            ];

            foreach ($scenario['pre'] as $pre) {
                $this->line('PRE ' . $pre['label'] . ':');
                if ($debug) {
                    $this->line(json_encode($pre['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                $response = $live ? $this->postCreateUpdate($kms, $pre['payload']) : ['dry_run' => true];
                $scenarioResult['pre'][] = [
                    'label' => $pre['label'],
                    'response' => $response,
                ];
            }

            $childPayload = [
                'products' => [[
                    'article_number' => $newArticle,
                    'articleNumber' => $newArticle,
                    'ean' => $newEan,
                    'name' => $name,
                    'price' => $price,
                    'purchase_price' => $purchasePrice,
                    'brand' => $brand,
                    'unit' => $unit,
                    'color' => $color,
                    'size' => $newSize,
                    'type_number' => $scenario['child']['type_number'],
                    'type_name' => $scenario['child']['type_name'],
                ]],
            ];

            $this->line('CHILD PAYLOAD:');
            if ($debug) {
                $this->line(json_encode($childPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            $beforeArticle = $this->lookupArticle($kms, $newArticle, $debug);
            $beforeEan = $newEan !== '' ? $this->lookupEan($kms, $newEan, $debug) : [];
            $response = $live ? $this->postCreateUpdate($kms, $childPayload) : ['dry_run' => true];
            $afterArticle = $this->lookupArticle($kms, $newArticle, $debug);
            $afterEan = $newEan !== '' ? $this->lookupEan($kms, $newEan, $debug) : [];
            $visible = count($afterArticle) > 0 || ($newEan !== '' && count($afterEan) > 0);

            $scenarioResult['child'] = [
                'payload' => $childPayload,
                'before_article_count' => count($beforeArticle),
                'before_ean_count' => count($beforeEan),
                'response' => $response,
                'after_article_count' => count($afterArticle),
                'after_ean_count' => count($afterEan),
            ];
            $scenarioResult['visible'] = $visible;
            $scenarioResult['snapshot'] = $visible ? ($afterArticle[0] ?? $afterEan[0] ?? null) : null;

            $this->line('RESULT: ' . ($visible ? 'VISIBLE' : 'NOT_VISIBLE'));
            if ($visible && $scenarioResult['snapshot']) {
                $this->line('SNAPSHOT: ' . json_encode($scenarioResult['snapshot'], JSON_UNESCAPED_UNICODE));
            }

            $results['scenarios'][$label] = $scenarioResult;

            if ($visible) {
                break;
            }
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $file = $dir . DIRECTORY_SEPARATOR . 'existing_family_sibling_' . $familyArticle . '_' . date('Ymd_His') . '.json';
            File::put($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line('JSON: ' . $file);
        }

        $this->newLine();
        $this->line(json_encode([
            'seed_article' => $seedArticle,
            'new_article' => $newArticle,
            'any_success' => collect($results['scenarios'])->contains(fn ($s) => !empty($s['visible'])),
            'live' => $live,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function lookupArticle($kms, string $article, bool $debug = false): array
    {
        $rows = $kms->getProducts([
            'offset' => 0,
            'limit' => 10,
            'articleNumber' => $article,
        ]);

        if ($debug) {
            $this->line('lookup article=' . $article . ' count=' . count($rows));
        }

        return is_array($rows) ? $rows : [];
    }

    private function lookupEan($kms, string $ean, bool $debug = false): array
    {
        $rows = $kms->getProducts([
            'offset' => 0,
            'limit' => 10,
            'ean' => $ean,
        ]);

        if ($debug) {
            $this->line('lookup ean=' . $ean . ' count=' . count($rows));
        }

        return is_array($rows) ? $rows : [];
    }

    private function postCreateUpdate($kms, array $payload): array
    {
        $response = $kms->createUpdate($payload);
        if (is_array($response)) {
            return $response;
        }
        if (is_object($response)) {
            return json_decode(json_encode($response), true) ?: ['response' => (string) json_encode($response)];
        }
        return ['response' => $response];
    }
}
