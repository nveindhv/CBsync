<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class KmsCompareFamily extends Command
{
    protected $signature = 'kms:compare:family
        {articles* : Variant article numbers to compare}
        {--source= : Optional KMS scan JSON file for fallback/offline compare}
        {--prefix-length=9 : Prefix length used as family bucket in fallback mode}
        {--dump-json : Write JSON result}
        {--dump-csv : Write CSV result}
        {--debug : Verbose output}';

    protected $description = 'Fallback wrapper: compare family via KMS scan dump when direct KMS article lookups are unreliable.';

    public function handle(): int
    {
        $this->warn('Direct KMS article lookups are unreliable in this environment; falling back to scan-based comparison.');

        $args = [
            'articles' => $this->argument('articles'),
            '--prefix-length' => (string) $this->option('prefix-length'),
        ];

        if ($this->option('source')) {
            $args['--source'] = $this->option('source');
        }
        if ($this->option('dump-json')) {
            $args['--dump-json'] = true;
        }
        if ($this->option('dump-csv')) {
            $args['--dump-csv'] = true;
        }
        if ($this->option('debug')) {
            $args['--debug'] = true;
        }

        return (int) $this->call('kms:compare:family-from-scan', $args);
    }
}
