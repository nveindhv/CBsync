<?php

namespace App\Console\Commands;

use App\Support\StoragePathResolver;
use Illuminate\Console\Command;

class KmsProbeFamilyBootstrap extends Command
{
    protected $signature = 'kms:probe:family-bootstrap {family} {--dump-json} {--debug}';
    protected $description = 'Prepare the exact scan-based bootstrap test report for one family.';

    public function handle(): int
    {
        $familyNo = trim((string) $this->argument('family'));
        $parentPath = StoragePathResolver::resolve('kms_scan/parent_payload_' . $familyNo . '.json');
        $payload = json_decode((string) file_get_contents($parentPath), true);

        $report = [
            'family' => $familyNo,
            'status' => 'prepared_for_live_probe',
            'next_live_action' => 'Use this parent payload in createUpdate, then test 1 representative child and 1 sibling.',
            'candidate_parent_payload' => $payload['candidate_parent_payload'] ?? null,
            'strip_before_parent_create' => $payload['variant_specific_fields_to_strip'] ?? [],
            'recommended_child_sequence' => $payload['inference_basis'] ?? [],
            'notes' => [
                'Primary truth source is scan data, not direct getProducts(articleNumber=...).',
                'If parent create works, immediately retry one known ERP child with same family prefix.',
                'If parent create fails, diff payload against one existing KMS family from the big scan.',
            ],
        ];

        $outDir = StoragePathResolver::ensurePrivateDir('kms_scan');
        $path = $outDir . DIRECTORY_SEPARATOR . 'family_bootstrap_probe_' . $familyNo . '.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line('JSON: ' . $path);

        return self::SUCCESS;
    }
}
