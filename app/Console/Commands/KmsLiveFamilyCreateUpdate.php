<?php

namespace App\Console\Commands;

use App\Services\Kms\KmsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class KmsLiveFamilyCreateUpdate extends Command
{
    protected $signature = 'kms:live:family-createupdate
        {family : ERP/KMS family prefix (existing workflow uses 9 or 11 chars)}
        {--child= : Explicit full child article_number to probe}
        {--sibling= : Explicit full sibling article_number to probe}
        {--parent-only : Only test the parent createUpdate step(s)}
        {--write-json : Write detailed matrix report to storage/app/private/kms_scan/live_family_probes}
        {--dry-run : Build and verify probe matrix without posting createUpdate}
        {--debug : Verbose output}
        {--force-all-scenarios : Do not stop after first success}';

    protected $description = 'Prepare and live-probe multiple parent/base/variant createUpdate strategies for one family.';

    public function handle(): int
    {
        $family = trim((string) $this->argument('family'));
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $forceAll = (bool) $this->option('force-all-scenarios');
        $parentOnly = (bool) $this->option('parent-only');
        $writeJson = (bool) $this->option('write-json');
        $childOpt = trim((string) ($this->option('child') ?? ''));
        $siblingOpt = trim((string) ($this->option('sibling') ?? ''));

        $seed = $this->loadSeedData($family, $childOpt, $siblingOpt, $debug);
        if (! $seed['ok']) {
            $this->warn($seed['message']);
            $this->line('Attempting to generate seed payloads via: php artisan kms:prep:family-live-probe ' . $family . ' --write-json ...');

            try {
                Artisan::call('kms:prep:family-live-probe', array_filter([
                    'family' => $family,
                    '--child' => $childOpt !== '' ? $childOpt : null,
                    '--sibling' => $siblingOpt !== '' ? $siblingOpt : null,
                    '--write-json' => true,
                    '--debug' => $debug ? true : null,
                ], static fn ($v) => $v !== null));

                if ($debug) {
                    $out = trim((string) Artisan::output());
                    if ($out !== '') {
                        $this->line($out);
                    }
                }
            } catch (\Throwable $e) {
                if ($debug) {
                    $this->warn('Seed generation failed: ' . get_class($e) . ' ' . $e->getMessage());
                }
            }

            $seed = $this->loadSeedData($family, $childOpt, $siblingOpt, $debug);
            if (! $seed['ok']) {
                $this->error($seed['message']);
                return self::FAILURE;
            }
        }

        $parentPayload = $seed['parent_payload'];
        $childPayload = $seed['child_payload'];
        $siblingPayload = $seed['sibling_payload'];
        $childArticle = (string) Arr::get($childPayload, 'products.0.article_number', Arr::get($childPayload, 'products.0.articleNumber', ''));
        $childEan = (string) Arr::get($childPayload, 'products.0.ean', '');
        $siblingArticle = (string) Arr::get($siblingPayload, 'products.0.article_number', Arr::get($siblingPayload, 'products.0.articleNumber', ''));
        $siblingEan = (string) Arr::get($siblingPayload, 'products.0.ean', '');
        $parentArticle = (string) Arr::get($parentPayload, 'products.0.article_number', Arr::get($parentPayload, 'products.0.articleNumber', $family));
        $parentEan = (string) Arr::get($parentPayload, 'products.0.ean', '');

        /** @var KmsClient $kms */
        $kms = app(KmsClient::class);

        $this->line('=== KMS FAMILY LIVE CREATEUPDATE MATRIX ===');
        $this->line('Family      : ' . $family);
        $this->line('Mode        : ' . ($dryRun ? 'DRY RUN' : 'LIVE'));
        $this->line('Parent-only : ' . ($parentOnly ? 'YES' : 'NO'));
        $this->line('Child       : ' . $childArticle);
        $this->line('Sibling     : ' . $siblingArticle);

        $scenarios = [
            [
                'key' => 'parent_only',
                'label' => 'Parent only',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $parentArticle, 'ean' => $parentEan],
                ],
            ],
            [
                'key' => 'parent_child',
                'label' => 'Parent + child',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $parentArticle, 'ean' => $parentEan],
                    ['name' => 'child', 'payload' => $childPayload, 'article' => $childArticle, 'ean' => $childEan],
                ],
            ],
            [
                'key' => 'parent_child_sibling',
                'label' => 'Parent + child + sibling',
                'steps' => [
                    ['name' => 'parent', 'payload' => $parentPayload, 'article' => $parentArticle, 'ean' => $parentEan],
                    ['name' => 'child', 'payload' => $childPayload, 'article' => $childArticle, 'ean' => $childEan],
                    ['name' => 'sibling', 'payload' => $siblingPayload, 'article' => $siblingArticle, 'ean' => $siblingEan],
                ],
            ],
        ];

        if ($parentOnly) {
            $scenarios = $this->filterParentOnlyScenarios($scenarios);
        }

        $report = [
            'family' => $family,
            'mode' => $dryRun ? 'dry-run' : 'live',
            'parent_only' => $parentOnly,
            'seed_files' => $seed['seed_files'],
            'scenarios' => [],
            'summary' => [
                'any_success' => false,
                'recommended_next_step' => null,
            ],
        ];

        foreach ($scenarios as $scenario) {
            $scenarioReport = [
                'key' => $scenario['key'],
                'label' => $scenario['label'],
                'success' => false,
                'success_components' => [],
                'steps' => [],
            ];

            $this->newLine();
            $this->line('---------- ' . $scenario['label'] . ' ----------');

            foreach ($scenario['steps'] as $step) {
                $correlationId = (string) Str::uuid();
                $before = $this->fetchOne($kms, $step['article'], $step['ean'], $debug, $correlationId . '-before');
                $posted = false;
                $error = null;

                if (! $dryRun) {
                    try {
                        $kms->post('kms/product/createUpdate', $step['payload'], $correlationId);
                        $posted = true;
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }
                }

                $after = $this->fetchOne($kms, $step['article'], $step['ean'], $debug, $correlationId . '-after');
                $visibleAfter = $after !== null;

                if ($visibleAfter) {
                    $scenarioReport['success_components'][] = $step['name'];
                }

                $scenarioReport['steps'][] = [
                    'name' => $step['name'],
                    'article' => $step['article'],
                    'ean' => $step['ean'] !== '' ? $step['ean'] : null,
                    'before' => [
                        'visible' => $before !== null,
                        'snapshot' => $before,
                    ],
                    'posted' => $posted,
                    'error' => $error,
                    'after' => [
                        'visible' => $visibleAfter,
                        'snapshot' => $after,
                    ],
                    'payload' => $debug ? $step['payload'] : null,
                ];
            }

            $scenarioReport['success'] = ! empty($scenarioReport['success_components']);
            $report['scenarios'][] = $scenarioReport;

            if ($scenarioReport['success']) {
                $report['summary']['any_success'] = true;
                if (! $forceAll) {
                    break;
                }
            }
        }

        $report['summary']['recommended_next_step'] = $report['summary']['any_success']
            ? 'Gebruik het eerste succesvolle scenario als basis voor definitieve family bootstrap.'
            : 'Nog geen zichtbare creatie: diff succesvolle historische create probe tegen deze matrix, vooral op parent/basis article_number en type_number lengte.';

        if ($writeJson) {
            $outDir = storage_path('app/private/kms_scan/live_family_probes');
            if (! is_dir($outDir)) {
                mkdir($outDir, 0777, true);
            }
            $ts = now()->format('Ymd_His');
            $path = $outDir . DIRECTORY_SEPARATOR . 'family_create_matrix_' . $family . '_' . $ts . '.json';
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('REPORT JSON : ' . $path);
        }

        $this->newLine();
        $this->line(json_encode($report['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function loadSeedData(string $family, string $childOpt = '', string $siblingOpt = '', bool $debug = false): array
    {
        $parentFile = $this->firstExisting([
            base_path("storage/app/private/kms_scan/parent_payload_{$family}.json"),
            base_path("storage/app/kms_scan/parent_payload_{$family}.json"),
            base_path("storage/app/private/kms_scan/live_family_probes/family_live_probe_{$family}_parent_payload.json"),
            base_path("storage/app/kms_scan/live_family_probes/family_live_probe_{$family}_parent_payload.json"),
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
                'message' => implode("\n", [
                    'Benodigde seed JSON niet gevonden (parent/child/sibling).',
                    'Tip: run: php artisan kms:prep:family-live-probe ' . $family . ' --write-json --debug',
                    'En zorg dat scan-first prerequisites bestaan: kms:scan:combine, erp:families:combine, kms:build:parent-payload.',
                ]),
            ];
        }

        $parentPayload = json_decode((string) file_get_contents($parentFile), true);
        $childPayload = json_decode((string) file_get_contents($childFile), true);
        $siblingPayload = json_decode((string) file_get_contents($siblingFile), true);

        if (! is_array($parentPayload) || ! is_array($childPayload) || ! is_array($siblingPayload)) {
            return [
                'ok' => false,
                'message' => 'Een of meer seed JSON bestanden konden niet worden gedecodeerd.',
            ];
        }

        if ($childOpt !== '') {
            Arr::set($childPayload, 'products.0.article_number', $childOpt);
            Arr::set($childPayload, 'products.0.articleNumber', $childOpt);
        }
        if ($siblingOpt !== '') {
            Arr::set($siblingPayload, 'products.0.article_number', $siblingOpt);
            Arr::set($siblingPayload, 'products.0.articleNumber', $siblingOpt);
        }

        if ($debug) {
            $this->line('Loaded seed files:');
            $this->line(' - ' . $parentFile);
            $this->line(' - ' . $childFile);
            $this->line(' - ' . $siblingFile);
        }

        return [
            'ok' => true,
            'parent_payload' => $parentPayload,
            'child_payload' => $childPayload,
            'sibling_payload' => $siblingPayload,
            'seed_files' => [
                'parent' => $parentFile,
                'child' => $childFile,
                'sibling' => $siblingFile,
            ],
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

    private function filterParentOnlyScenarios(array $scenarios): array
    {
        foreach ($scenarios as &$scenario) {
            $steps = is_array($scenario['steps'] ?? null) ? $scenario['steps'] : [];
            if ($steps === []) {
                $scenario['steps'] = [];
                continue;
            }

            $parentSteps = array_values(array_filter($steps, static fn ($step) => (string) ($step['name'] ?? '') === 'parent'));
            if ($parentSteps === []) {
                $parentSteps = [$steps[0]];
            }

            $scenario['steps'] = $parentSteps;
        }
        unset($scenario);

        return $scenarios;
    }

    private function fetchOne(KmsClient $kms, string $article, ?string $ean, bool $debug = false, ?string $correlationId = null): ?array
    {
        $res = $kms->post('kms/product/getProducts', [
            'offset' => 0,
            'limit' => 5,
            'articleNumber' => $article,
        ], $correlationId);

        $items = $this->normalize($res);
        if ($debug) {
            $this->line('lookup article=' . $article . ' count=' . count($items));
        }

        foreach ($items as $item) {
            if ((string) Arr::get($item, 'articleNumber') === $article) {
                return $item;
            }
        }

        if ($ean !== null && $ean !== '') {
            $res2 = $kms->post('kms/product/getProducts', [
                'offset' => 0,
                'limit' => 5,
                'ean' => $ean,
            ], $correlationId);
            $items2 = $this->normalize($res2);
            if ($debug) {
                $this->line('lookup ean=' . $ean . ' count=' . count($items2));
            }
            foreach ($items2 as $item) {
                if ((string) Arr::get($item, 'articleNumber') === $article || (string) Arr::get($item, 'ean') === $ean) {
                    return $item;
                }
            }
        }

        return null;
    }

    private function normalize($res): array
    {
        if (! is_array($res) || $res === []) {
            return [];
        }

        $keys = array_keys($res);
        $isList = $keys === range(0, count($keys) - 1);
        $items = $isList ? $res : array_values($res);

        return array_values(array_filter($items, static fn ($row) => is_array($row)));
    }
}
