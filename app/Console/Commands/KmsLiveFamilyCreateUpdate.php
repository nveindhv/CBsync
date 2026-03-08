<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class KmsLiveFamilyCreateUpdate extends Command
{
    protected $signature = 'kms:live:family-createupdate
        {family : ERP/KMS family prefix}
        {--child= : Explicit full child article_number to probe}
        {--sibling= : Explicit full sibling article_number to probe}
        {--parent-only : Only test the parent createUpdate step(s)}
        {--write-json : Write detailed matrix report}
        {--dry-run : Build and verify probe matrix without posting createUpdate}
        {--debug : Verbose output}';

    protected $description = 'Prepare and live-probe multiple parent/base/variant createUpdate strategies for one family.';

    public function handle(KmsClient $kms): int
    {
        $family = trim((string) $this->argument('family'));
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $parentOnly = (bool) $this->option('parent-only');

        $seed = $this->loadSeedData($family);
        if (! $seed['ok']) {
            $this->error($seed['message']);
            return self::FAILURE;
        }

        $parentPayload = $seed['parent_payload'];
        $childPayload = $seed['child_payload'];
        $siblingPayload = $seed['sibling_payload'];
        $childArticle = (string) data_get($childPayload, 'products.0.articleNumber', data_get($childPayload, 'products.0.article_number', ''));
        $childEan = (string) data_get($childPayload, 'products.0.ean', '');
        $siblingArticle = (string) data_get($siblingPayload, 'products.0.articleNumber', data_get($siblingPayload, 'products.0.article_number', ''));
        $siblingEan = (string) data_get($siblingPayload, 'products.0.ean', '');

        $this->line('Loaded seed files:');
        foreach ($seed['files'] as $file) {
            $this->line(' - ' . $file);
        }
        $this->line('=== KMS FAMILY LIVE CREATEUPDATE MATRIX ===');
        $this->line('Family      : ' . $family);
        $this->line('Mode        : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->line('Parent-only : ' . ($parentOnly ? 'YES' : 'NO'));
        $this->line('Child       : ' . $childArticle);
        $this->line('Sibling     : ' . $siblingArticle);
        $this->newLine();

        $scenarios = [
            [
                'name' => 'Parent only',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $family, 'ean' => ''],
                ],
            ],
            [
                'name' => 'Parent + child',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $family, 'ean' => ''],
                    ['name' => 'child', 'payload' => $childPayload, 'article' => $childArticle, 'ean' => $childEan],
                ],
            ],
            [
                'name' => 'Parent + child + sibling',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $family, 'ean' => ''],
                    ['name' => 'child', 'payload' => $childPayload, 'article' => $childArticle, 'ean' => $childEan],
                    ['name' => 'sibling', 'payload' => $siblingPayload, 'article' => $siblingArticle, 'ean' => $siblingEan],
                ],
            ],
        ];

        if ($parentOnly) {
            foreach ($scenarios as &$scenario) {
                $scenario['steps'] = [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $family, 'ean' => ''],
                ];
            }
            unset($scenario);
        }

        $report = [
            'family' => $family,
            'mode' => $dryRun ? 'dry-run' : 'live',
            'parent_only' => $parentOnly,
            'scenarios' => [],
        ];

        $anySuccess = false;

        foreach ($scenarios as $scenario) {
            $this->sectionLine($scenario['name']);
            $scenarioReport = ['name' => $scenario['name'], 'steps' => []];

            foreach ($scenario['steps'] as $step) {
                $before = $this->fetchOne($kms, $step['article'], $step['ean'], $debug);
                if ($debug) {
                    $this->line('lookup article=' . $step['article'] . ' count=' . $before['count_article']);
                    if ($step['ean'] !== '') {
                        $this->line('lookup ean=' . $step['ean'] . ' count=' . $before['count_ean']);
                    }
                } else {
                    $this->line('lookup article=' . $step['article'] . ' count=' . $before['count_article']);
                    if ($step['ean'] !== '') {
                        $this->line('lookup ean=' . $step['ean'] . ' count=' . $before['count_ean']);
                    }
                }

                $response = null;
                $error = null;
                $correlationId = (string) Str::uuid();

                if (! $dryRun) {
                    try {
                        $response = $kms->post('kms/product/createUpdate', $step['payload'], $correlationId);
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }
                }

                $after = $this->fetchOne($kms, $step['article'], $step['ean'], $debug);
                $this->line('lookup article=' . $step['article'] . ' count=' . $after['count_article']);
                if ($step['ean'] !== '') {
                    $this->line('lookup ean=' . $step['ean'] . ' count=' . $after['count_ean']);
                }

                $createdNow = ($before['count_article'] === 0 && $after['count_article'] > 0)
                    || ($step['ean'] !== '' && $before['count_ean'] === 0 && $after['count_ean'] > 0);

                $stepReport = [
                    'name' => $step['name'],
                    'article' => $step['article'],
                    'ean' => $step['ean'],
                    'correlation_id' => $correlationId,
                    'before' => $before,
                    'after' => $after,
                    'create_update_response' => $response,
                    'error' => $error,
                    'created_now' => $createdNow,
                ];
                $scenarioReport['steps'][] = $stepReport;
                if ($createdNow) {
                    $anySuccess = true;
                }
            }

            $report['scenarios'][] = $scenarioReport;
            $this->newLine();
        }

        $report['summary'] = [
            'any_success' => $anySuccess,
            'recommended_next_step' => $anySuccess
                ? 'Gebruik het eerste scenario waar before=0 en after>0 als bewijs van nieuwe zichtbaarheid.'
                : 'Nog geen zichtbare creatie: diff succesvolle historische create probe tegen deze matrix, vooral op parent/basis article_number en type_number lengte.',
        ];

        $path = $this->reportPath('family_create_matrix_' . $family . '_' . now()->format('Ymd_His') . '.json');
        if ((bool) $this->option('write-json')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $this->line('REPORT JSON : ' . $path);
        $this->newLine();
        $this->line(json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function fetchOne(KmsClient $kms, string $article, string $ean, bool $debug): array
    {
        $countArticle = 0;
        $countEan = 0;
        $articleRow = null;
        $eanRow = null;
        $errorArticle = null;
        $errorEan = null;

        if ($article !== '') {
            try {
                $articleResp = $kms->post('kms/product/getProducts', ['articleNumber' => $article]);
                $articleRows = $this->extractRows($articleResp);
                $countArticle = count($articleRows);
                $articleRow = $this->compactRow($articleRows[0] ?? null);
            } catch (\Throwable $e) {
                $errorArticle = $e->getMessage();
                if ($debug) {
                    $this->warn('lookup failed for article=' . $article . ' : ' . $e->getMessage());
                }
            }
        }

        if ($ean !== '') {
            try {
                $eanResp = $kms->post('kms/product/getProducts', ['ean' => $ean]);
                $eanRows = $this->extractRows($eanResp);
                $countEan = count($eanRows);
                $eanRow = $this->compactRow($eanRows[0] ?? null);
            } catch (\Throwable $e) {
                $errorEan = $e->getMessage();
                if ($debug) {
                    $this->warn('lookup failed for ean=' . $ean . ' : ' . $e->getMessage());
                }
            }
        }

        return [
            'count_article' => $countArticle,
            'count_ean' => $countEan,
            'article_row' => $articleRow,
            'ean_row' => $eanRow,
            'error_article' => $errorArticle,
            'error_ean' => $errorEan,
        ];
    }

    private function extractRows($resp): array
    {
        if (! is_array($resp)) {
            return [];
        }
        if (isset($resp['products']) && is_array($resp['products'])) {
            return array_values(array_filter($resp['products'], 'is_array'));
        }
        if (isset($resp[0]) && is_array($resp[0])) {
            return array_values(array_filter($resp, 'is_array'));
        }
        return [];
    }

    private function compactRow($row): ?array
    {
        if (! is_array($row)) {
            return null;
        }
        $keys = [
            'id', 'articleNumber', 'article_number', 'ean', 'name', 'price', 'purchasePrice', 'purchase_price',
            'unit', 'brand', 'color', 'size', 'supplierName', 'supplier_name', 'typeNumber', 'type_number',
            'typeName', 'type_name', 'modifyDate',
        ];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $out[$key] = $row[$key];
            }
        }
        return $out;
    }

    private function loadSeedData(string $family): array
    {
        $parentFile = $this->firstExisting([
            storage_path('app/private/kms_scan/parent_payload_' . $family . '.json'),
            storage_path('app/private/kms_scan/live_family_probes/family_live_probe_' . $family . '_parent_payload.json'),
            storage_path('app/kms_scan/parent_payload_' . $family . '.json'),
        ]);
        $childFile = $this->firstExisting([
            storage_path('app/private/kms_scan/live_family_probes/family_live_probe_' . $family . '_child_payload.json'),
            storage_path('app/kms_scan/live_family_probes/family_live_probe_' . $family . '_child_payload.json'),
        ]);
        $siblingFile = $this->firstExisting([
            storage_path('app/private/kms_scan/live_family_probes/family_live_probe_' . $family . '_sibling_payload.json'),
            storage_path('app/kms_scan/live_family_probes/family_live_probe_' . $family . '_sibling_payload.json'),
        ]);

        if (! $parentFile || ! $childFile || ! $siblingFile) {
            return [
                'ok' => false,
                'message' => 'Benodigde seed JSON niet gevonden. Run eerst kms:prep:family-live-probe ' . $family,
            ];
        }

        $parentPayload = json_decode((string) file_get_contents($parentFile), true);
        if (is_array($parentPayload) && isset($parentPayload['candidate_parent_payload'])) {
            $parentPayload = $parentPayload['candidate_parent_payload'];
        }

        return [
            'ok' => true,
            'files' => [$parentFile, $childFile, $siblingFile],
            'parent_payload' => is_array($parentPayload) ? $parentPayload : [],
            'child_payload' => json_decode((string) file_get_contents($childFile), true) ?: [],
            'sibling_payload' => json_decode((string) file_get_contents($siblingFile), true) ?: [],
        ];
    }

    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    private function reportPath(string $filename): string
    {
        $dir = storage_path('app/private/kms_scan/live_family_probes');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . DIRECTORY_SEPARATOR . $filename;
    }

    private function sectionLine(string $text): void
    {
        $this->line('---------- ' . $text . ' ----------');
    }
}
