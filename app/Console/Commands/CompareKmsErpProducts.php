<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompareKmsErpProducts extends Command
{
    protected $signature = 'compare:kms-erp:products
        {--kms-limit=5}
        {--kms-offset=0}
        {--erp-start-offset=0}
        {--erp-scan-limit=200}
        {--erp-max-offset=120000}
        {--erp-first5}
        {--dump}
    ';

    protected $description = 'Compare KMS products with ERP products by scanning and matching on articleNumber/productCode and EAN';

    public function handle(): int
    {
        // Backwards-compatible wrapper.
        $args = [
            '--kms-limit' => $this->option('kms-limit'),
            '--kms-offset' => $this->option('kms-offset'),
            '--erp-start-offset' => $this->option('erp-start-offset'),
            '--erp-scan-limit' => $this->option('erp-scan-limit'),
            '--erp-max-offset' => $this->option('erp-max-offset'),
        ];

        if ((bool) $this->option('erp-first5')) {
            $args['--erp-first5'] = true;
        }
        if ((bool) $this->option('dump')) {
            $args['--dump'] = true;
        }

        return $this->call('compare:kms-erp:products:scan', $args);
    }
}
