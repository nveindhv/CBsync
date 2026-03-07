<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class KmsLiveFamilyCreateUpdate extends Command
{
    protected $signature = 'kms:live:family-createupdate
        {family : ERP/KMS family prefix (existing workflow uses 11 chars)}
        {--child= : Explicit full child article_number to probe}
        {--sibling= : Explicit full sibling article_number to probe}
        {--write-json : Write detailed matrix report to storage/app/private/kms_scan/live_family_probes}
        {--dry-run : Build and verify probe matrix without posting createUpdate}
        {--debug : Verbose output}
        {--force-all-scenarios : Do not stop after first success}';

    protected $description = 'Prepare and live-probe multiple parent/base/variant createUpdate strategies for one family.';

    public function __construct(private readonly KmsClient $kms)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $family = trim((string) $this->argument('family'));
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $forceAll = (bool) $this->option('force-all-scenarios');

        $seed = $this->loadSeedData($family);
        if (! $seed['ok']) {
            $this->error($seed['message']);
            return self::FAILURE;
        }

        $parentPayload = $seed['parent_payload'];
        $childPayload = $seed['child_payload'];
        $siblingPayload = $seed['sibling_payload'];

        $childArticle = (string) ($this->option('child') ?: data_get($childPayload, 'products.0.article_number') ?: data_get($childPayload, 'products.0.articleNumber'));
        $siblingArticle = (string) ($this->option('sibling') ?: data_get($siblingPayload, 'products.0.article_number') ?: data_get($siblingPayload, 'products.0.articleNumber'));

        if ($childArticle === '' || $siblingArticle === '') {
            $this->error('Child/sibling articles ontbreken. Geef --child en --sibling mee of zorg dat eerdere live probe payload JSONs bestaan.');
            return self::FAILURE;
        }

        $family9 = substr($family, 0, 9);
        $childBase12 = substr($childArticle, 0, min(12, strlen($childArticle)));
        $siblingBase12 = substr($siblingArticle, 0, min(12, strlen($siblingArticle)));

        $baseName = (string) (data_get($parentPayload, 'products.0.name') ?: data_get($childPayload, 'products.0.type_name') ?: data_get($childPayload, 'products.0.typeName') ?: data_get($childPayload, 'products.0.name') ?: $family);
        $variantName = (string) (data_get($childPayload, 'products.0.name') ?: $baseName);
        $brand = (string) (data_get($childPayload, 'products.0.brand') ?: data_get($parentPayload, 'products.0.brand') ?: '');
        $unit = (string) (data_get($childPayload, 'products.0.unit') ?: data_get($parentPayload, 'products.0.unit') ?: 'STK');

        $this->line('=== KMS FAMILY LIVE CREATEUPDATE MATRIX ===');
        $this->line('Family      : ' . $family);
        $this->line('Mode        : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->line('Hypotheses  : 9-char type_number + optional 12-char basis article + full variant');
        $this->line('Child       : ' . $childArticle);
        $this->line('Sibling     : ' . $siblingArticle);
        $this->line('Type(9)     : ' . $family9);
        $this->line('Basis(12)   : ' . $childBase12 . ' / ' . $siblingBase12);
        $this->newLine();

        $scenarios = $this->buildScenarios(
            family11: $family,
            family9: $family9,
            childBase12: $childBase12,
            siblingBase12: $siblingBase12,
            parentPayload: $parentPayload,
            childPayload: $childPayload,
            siblingPayload: $siblingPayload,
            baseName: $baseName,
            variantName: $variantName,
            brand: $brand,
            unit: $unit,
        );

        $report = [
            'family' => $family,
            'mode' => $dryRun ? 'dry-run' : 'live',
            'new_customer_hint' => [
                'basisartikel_12_cijfers' => true,
                'type_number_9_chars' => $family9,
                'note' => 'We bepalen hoofdproduct nu als matrix: type/head (9 chars), basisartikel (12 chars), daarna variant.',
            ],
            'seed_files' => $seed['files'],
            'scenarios' => [],
            'summary' => [],
        ];

        $anySuccess = false;

        foreach ($scenarios as $scenario) {
            $this->sectionLine(sprintf('[%s] %s', $scenario['key'], $scenario['label']));

            $scenarioResult = [
                'key' => $scenario['key'],
                'label' => $scenario['label'],
                'articles' => [],
                'posted' => [],
                'success' => false,
            ];

            foreach ($scenario['steps'] as $step) {
                $lookupArticle = (string) data_get($step, 'lookup_article');
                $lookupEan = (string) data_get($step, 'lookup_ean', '');
                $payload = $step['payload'];
                $stepName = (string) $step['name'];

                $before = $this->lookupVisibility($lookupArticle, $lookupEan);
                $scenarioResult['articles'][$stepName]['before'] = $before;

                if ($debug) {
                    $this->line("lookup article={$lookupArticle} count={$before['article_count']}");
                    if ($lookupEan !== '') {
                        $this->line("lookup ean={$lookupEan} count={$before['ean_count']}");
                    }
                    $this->line(strtoupper($stepName) . ' PAYLOAD:');
                    $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                if (! $dryRun && ! $before['visible']) {
                    $postResult = $this->postCreateUpdate($payload);
                    $scenarioResult['posted'][$stepName] = $postResult;
                    if ($debug) {
                        $this->line($stepName . ' createUpdate response: ' . json_encode($postResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                }

                $after = $this->lookupVisibility($lookupArticle, $lookupEan);
                $scenarioResult['articles'][$stepName]['after'] = $after;

                $becameVisible = (! $before['visible']) && $after['visible'];
                $scenarioResult['articles'][$stepName]['became_visible'] = $becameVisible;

                $statusText = $after['visible']
                    ? 'VISIBLE'
                    : ($dryRun ? 'DRY-ONLY / NOT VISIBLE' : 'NOT VISIBLE');

                $this->line(sprintf('%s => %s', $stepName, $statusText));
            }

            $parentVisible = (bool) data_get($scenarioResult, 'articles.parent.after.visible');
            $basisVisible = (bool) data_get($scenarioResult, 'articles.basis.after.visible') || (bool) data_get($scenarioResult, 'articles.basis_sibling_color.after.visible');
            $childVisible = (bool) data_get($scenarioResult, 'articles.child.after.visible');
            $siblingVisible = (bool) data_get($scenarioResult, 'articles.sibling.after.visible');

            $scenarioResult['success'] = $childVisible || $siblingVisible || $basisVisible || $parentVisible;
            $scenarioResult['success_components'] = compact('parentVisible', 'basisVisible', 'childVisible', 'siblingVisible');
            $report['scenarios'][] = $scenarioResult;

            if ($scenarioResult['success']) {
                $anySuccess = true;
                $this->info('Scenario had zichtbare creatie/update.');
                if (! $forceAll) {
                    break;
                }
            } else {
                $this->warn('Scenario nog geen zichtbare creatie.');
            }

            $this->newLine();
        }

        $report['summary'] = [
            'any_success' => $anySuccess,
            'family' => $family,
            'dry_run' => $dryRun,
            'recommended_next_step' => $anySuccess
                ? 'Gebruik het eerste succesvolle scenario als basis voor definitieve family bootstrap.'
                : 'Nog geen zichtbare creatie: diff succesvolle historische create probe tegen deze matrix, vooral op parent/basis article_number en type_number lengte.',
        ];

        if ((bool) $this->option('write-json')) {
            $dir = $this->ensureDir('storage/app/private/kms_scan/live_family_probes');
            $file = $dir . DIRECTORY_SEPARATOR . 'family_create_matrix_' . $family . '_' . now()->format('Ymd_His') . '.json';
            File::put($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('REPORT JSON : ' . $file);
        }

        $this->newLine();
        $this->line(json_encode([
            'family' => $family,
            'any_success' => $anySuccess,
            'dry_run' => $dryRun,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function loadSeedData(string $family): array
    {
        $parentFile = $this->firstExisting([
            base_path("storage/app/private/kms_scan/parent_payload_{$family}.json"),
            base_path("storage/app/kms_scan/parent_payload_{$family}.json"),
            base_path("storage/app/private/kms_scan/live_family_probes/family_live_probe_{$family}_parent_payload.json"),
        ]);
        $childFile = $this->firstExisting([
            base_path("storage/app/private/kms_scan/live_family_probes/family_live_probe_{$family}_child_payload.json"),
            base_path("storage/app/kms_scan/live_family_probes/family_live_probe_{$family}_child_payload.json"),
        ]);
        $siblingFile = $this->firstExisting([
            base_path("storage/app/private/kms_scan/live_family_probes/family_live_probe_{$family}_sibling_payload.json"),
            base_path("storage/app/kms_scan/live_family_probes/family_live_probe_{$family}_sibling_payload.json"),
        ]);

        if (! $parentFile || ! $childFile || ! $siblingFile) {
            return [
                'ok' => false,
                'message' => 'Benodigde seed JSON niet gevonden. Verwacht parent_payload en live_family_probe child/sibling payloads in storage/app/private/kms_scan/...',
            ];
        }

        return [
            'ok' => true,
            'parent_payload' => $this->readJson($parentFile),
            'child_payload' => $this->readJson($childFile),
            'sibling_payload' => $this->readJson($siblingFile),
            'files' => [
                'parent' => $parentFile,
                'child' => $childFile,
                'sibling' => $siblingFile,
            ],
        ];
    }

    private function buildScenarios(
        string $family11,
        string $family9,
        string $childBase12,
        string $siblingBase12,
        array $parentPayload,
        array $childPayload,
        array $siblingPayload,
        string $baseName,
        string $variantName,
        string $brand,
        string $unit,
    ): array
    {
        $parent11 = $this->makeParentPayload($family11, $family11, '', $baseName, $brand, $unit);
        $parent9 = $this->makeParentPayload($family9, $family9, '', $baseName, $brand, $unit);
        $parent11Type9 = $this->makeParentPayload($family11, $family9, '', $baseName, $brand, $unit);

        $basis12Type9Child = $this->makeBasisPayload($childPayload, $childBase12, $family9, $baseName);
        $basis12Type9Sibling = $this->makeBasisPayload($siblingPayload, $siblingBase12, $family9, $baseName);
        $basis12Type11Child = $this->makeBasisPayload($childPayload, $childBase12, $family11, $baseName);

        $childType11 = $this->retargetVariant($childPayload, $family11, $baseName, $variantName);
        $siblingType11 = $this->retargetVariant($siblingPayload, $family11, $baseName, $variantName);
        $childType9 = $this->retargetVariant($childPayload, $family9, $baseName, $variantName);
        $siblingType9 = $this->retargetVariant($siblingPayload, $family9, $baseName, $variantName);

        return [
            [
                'key' => 'baseline_family11',
                'label' => 'Huidige baseline: family article/type op 11 chars',
                'steps' => [
                    $this->step('parent', $parent11),
                    $this->step('child', $childType11),
                    $this->step('sibling', $siblingType11),
                ],
            ],
            [
                'key' => 'type9_parent9',
                'label' => 'Oude programmeur hypothese: hoofd/type op 9 chars',
                'steps' => [
                    $this->step('parent', $parent9),
                    $this->step('child', $childType9),
                    $this->step('sibling', $siblingType9),
                ],
            ],
            [
                'key' => 'type9_parent11',
                'label' => 'Hoofdartikel 11 chars, maar type_number 9 chars',
                'steps' => [
                    $this->step('parent', $parent11Type9),
                    $this->step('child', $childType9),
                    $this->step('sibling', $siblingType9),
                ],
            ],
            [
                'key' => 'basis12_then_variant_type9',
                'label' => '12-cijfer basisartikel (kleur zonder maat) + variants op type 9',
                'steps' => [
                    $this->step('parent', $parent9),
                    $this->step('basis', $basis12Type9Child),
                    $this->step('child', $childType9),
                    $this->step('sibling', $siblingType9),
                ],
            ],
            [
                'key' => 'basis12_only_type9',
                'label' => 'Zonder hoofdrecord: direct 12-cijfer basisartikel + variants op type 9',
                'steps' => [
                    $this->step('basis', $basis12Type9Child),
                    $this->step('child', $childType9),
                    $this->step('sibling', $siblingType9),
                ],
            ],
            [
                'key' => 'basis12_color_pair_type9',
                'label' => 'Twee basisartikelen per kleur (child/sibling) + variants op type 9',
                'steps' => [
                    $this->step('parent', $parent9),
                    $this->step('basis', $basis12Type9Child),
                    $this->step('basis_sibling_color', $basis12Type9Sibling),
                    $this->step('child', $childType9),
                    $this->step('sibling', $siblingType9),
                ],
            ],
            [
                'key' => 'basis12_type11_control',
                'label' => 'Controle: 12-cijfer basisartikel maar type_number op 11 chars',
                'steps' => [
                    $this->step('basis', $basis12Type11Child),
                    $this->step('child', $childType11),
                    $this->step('sibling', $siblingType11),
                ],
            ],
        ];
    }

    private function step(string $name, array $payload): array
    {
        $product = (array) data_get($payload, 'products.0', []);
        $article = (string) ($product['article_number'] ?? $product['articleNumber'] ?? '');
        $ean = (string) ($product['ean'] ?? '');

        return [
            'name' => $name,
            'payload' => $payload,
            'lookup_article' => $article,
            'lookup_ean' => $ean !== '0' ? $ean : '',
        ];
    }

    private function makeParentPayload(string $article, string $typeNumber, string $typeName, string $name, string $brand, string $unit): array
    {
        return [
            'products' => [[
                'article_number' => $article,
                'articleNumber' => $article,
                'name' => $name,
                'brand' => $brand,
                'unit' => $unit,
                'type_number' => $typeNumber,
                'typeNumber' => $typeNumber,
                'type_name' => $typeName,
                'typeName' => $typeName,
            ]],
        ];
    }

    private function makeBasisPayload(array $variantPayload, string $basisArticle, string $typeNumber, string $typeName): array
    {
        $product = (array) data_get($variantPayload, 'products.0', []);

        $basis = [
            'article_number' => $basisArticle,
            'articleNumber' => $basisArticle,
            'name' => (string) ($product['name'] ?? ($typeName ?: $basisArticle)),
            'brand' => (string) ($product['brand'] ?? ''),
            'unit' => (string) ($product['unit'] ?? 'STK'),
            'price' => Arr::get($product, 'price'),
            'purchase_price' => Arr::get($product, 'purchase_price'),
            'type_number' => $typeNumber,
            'typeNumber' => $typeNumber,
            'type_name' => $typeName,
            'typeName' => $typeName,
            'color' => (string) ($product['color'] ?? ''),
        ];

        return [
            'products' => [[
                ...array_filter($basis, static fn ($value) => $value !== null && $value !== ''),
            ]],
        ];
    }

    private function retargetVariant(array $variantPayload, string $typeNumber, string $typeName, string $name): array
    {
        $product = (array) data_get($variantPayload, 'products.0', []);
        $product['type_number'] = $typeNumber;
        $product['typeNumber'] = $typeNumber;
        $product['type_name'] = $typeName;
        $product['typeName'] = $typeName;
        $product['name'] = $product['name'] ?? $name;

        return ['products' => [$product]];
    }

    private function lookupVisibility(string $article, string $ean = ''): array
    {
        $articleRows = $article !== ''
            ? $this->normalizeProducts($this->safePost('kms/product/getProducts', ['offset' => 0, 'limit' => 10, 'articleNumber' => $article]))
            : [];
        $eanRows = ($ean !== '')
            ? $this->normalizeProducts($this->safePost('kms/product/getProducts', ['offset' => 0, 'limit' => 10, 'ean' => $ean]))
            : [];

        return [
            'visible' => count($articleRows) > 0 || count($eanRows) > 0,
            'article_count' => count($articleRows),
            'ean_count' => count($eanRows),
            'article_sample' => $articleRows[0] ?? null,
            'ean_sample' => $eanRows[0] ?? null,
        ];
    }

    private function postCreateUpdate(array $payload): array
    {
        return $this->safePost('kms/product/createUpdate', $payload);
    }

    private function safePost(string $path, array $payload): array
    {
        try {
            $result = $this->kms->post($path, $payload);
            return is_array($result) ? $result : ['raw' => $result];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ];
        }
    }

    private function normalizeProducts(array $raw): array
    {
        if ($raw === []) {
            return [];
        }

        if (array_is_list($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }

        if (isset($raw['products']) && is_array($raw['products'])) {
            return array_values(array_filter($raw['products'], 'is_array'));
        }

        $values = array_values($raw);
        $arrayValues = array_values(array_filter($values, 'is_array'));

        if ($arrayValues !== []) {
            return $arrayValues;
        }

        return isset($raw['articleNumber']) ? [$raw] : [];
    }

    private function readJson(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if ($path && File::exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function ensureDir(string $relative): string
    {
        $path = base_path($relative);
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0777, true);
        }

        return $path;
    }

    private function sectionLine(string $text): void
    {
        $this->line(str_repeat('-', 10) . ' ' . $text . ' ' . str_repeat('-', 10));
    }
}
