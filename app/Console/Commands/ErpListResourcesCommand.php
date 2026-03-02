<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class ErpListResourcesCommand extends Command
{
    protected $signature = 'erp:resources';
    protected $description = 'List ERP resources that are configured as GET-enabled (hardcoded list; no schema file)';

    public function handle(): int
    {
        try {
            $resources = (array) config('erp_gets.resources', []);

            $this->info('GET-enabled resources: ' . count($resources));
            foreach ($resources as $r) {
                $this->line($r);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('List failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
