<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class KmsProbeShortestCreatePath extends Command
{
    protected $signature = 'kms:probe:shortest-create-path
        {family9 : 9-digit type/family number}
        {child : Known child/full variant article}
        {--child-ean= : Child EAN}
        {--child-color= : Child color}
        {--child-size= : Child size}
        {--type-name= : Proven type_name / family name}
        {--brand= : Brand}
        {--unit=STK : Unit}
        {--sibling= : Optional sibling/full variant article}
        {--sibling-ean= : Optional sibling EAN}
        {--sibling-color= : Optional sibling color}
        {--sibling-size= : Optional sibling size}
        {--basis12= : Optional basis article; defaults to substr(child,0,12)}
        {--basis-color= : Optional basis color; defaults to child color}
        {--live : Actually call the existing create-update probe}
        {--write-json : Write a consolidated report}
        {--debug : Pass debug into inner probes}';

    protected $description = 'Shortest path wrapper around the proven kms:probe:create-update flow: basis12 first, then child, then sibling.';

    public function handle(): int
    {
        $family9 = trim((string) $this->argument('family9'));
        $child = trim((string) $this->argument('child'));
        $basis12 = trim((string) ($this->option('basis12') ?: substr($child, 0, 12)));
        $childEan = trim((string) $this->option('child-ean'));
        $childColor = trim((string) $this->option('child-color'));
        $childSize = trim((string) $this->option('child-size'));
        $typeName = trim((string) $this->option('type-name'));
        $brand = trim((string) $this->option('brand'));
        $unit = trim((string) $this->option('unit'));
        $basisColor = trim((string) ($this->option('basis-color') ?: $childColor));
        $sibling = trim((string) $this->option('sibling'));
        $siblingEan = trim((string) $this->option('sibling-ean'));
        $siblingColor = trim((string) $this->option('sibling-color'));
        $siblingSize = trim((string) $this->option('sibling-size'));
        $live = (bool) $this->option('live');
        $debug = (bool) $this->option('debug');

        $this->line('=== KMS SHORTEST CREATE PATH ===');
        $this->line('Mode    : ' . ($live ? 'LIVE' : 'DRY RUN'));
        $this->line('Family9 : ' . $family9);
        $this->line('Basis12 : ' . $basis12);
        $this->line('Child   : ' . $child);
        $this->line('Sibling : ' . ($sibling ?: '-'));

        $steps = [];

        $basisArgs = [
            'article' => $basis12,
            '--brand' => $brand,
            '--unit' => $unit,
            '--color' => $basisColor,
            '--type-number' => $family9,
            '--type-name' => $typeName,
            '--description' => $typeName,
        ];
        if ($childEan !== '') {
            $basisArgs['--ean'] = $childEan;
        }
        if ($debug) {
            $basisArgs['--debug'] = true;
        }
        if (! $live) {
            $basisArgs['--dry-run'] = true;
        }
        $steps[] = ['label' => 'basis12', 'args' => $basisArgs];

        $childArgs = [
            'article' => $child,
            '--ean' => $childEan,
            '--brand' => $brand,
            '--unit' => $unit,
            '--color' => $childColor,
            '--size' => $childSize,
            '--type-number' => $family9,
            '--type-name' => $typeName,
            '--description' => $typeName,
        ];
        if ($debug) {
            $childArgs['--debug'] = true;
        }
        if (! $live) {
            $childArgs['--dry-run'] = true;
        }
        $steps[] = ['label' => 'child', 'args' => $childArgs];

        if ($sibling !== '') {
            $sibArgs = [
                'article' => $sibling,
                '--ean' => $siblingEan,
                '--brand' => $brand,
                '--unit' => $unit,
                '--color' => $siblingColor,
                '--size' => $siblingSize,
                '--type-number' => $family9,
                '--type-name' => $typeName,
                '--description' => $typeName,
            ];
            if ($debug) {
                $sibArgs['--debug'] = true;
            }
            if (! $live) {
                $sibArgs['--dry-run'] = true;
            }
            $steps[] = ['label' => 'sibling', 'args' => $sibArgs];
        }

        $report = [];
        foreach ($steps as $step) {
            $this->newLine();
            $this->info('--- ' . strtoupper($step['label']) . ' ---');
            Artisan::call('kms:probe:create-update', $step['args']);
            $output = Artisan::output();
            $this->line(rtrim($output));
            $report[] = [
                'label' => $step['label'],
                'args' => $step['args'],
                'output' => $output,
            ];
        }

        if ($this->option('write-json')) {
            $dir = storage_path('app/private/kms_scan/live_family_probes');
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $path = $dir . '/shortest_create_path_' . $family9 . '.json';
            file_put_contents($path, json_encode([
                'family9' => $family9,
                'mode' => $live ? 'live' : 'dry-run',
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info('JSON: ' . $path);
        }

        return self::SUCCESS;
    }
}
