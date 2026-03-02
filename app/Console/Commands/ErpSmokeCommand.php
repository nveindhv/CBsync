<?php

namespace App\Console\Commands;

use App\Services\ERP\ERPClient;
use Illuminate\Console\Command;
use Throwable;

class ErpSmokeCommand extends Command
{
    protected $signature = 'erp:smoke {resource=products}';
    protected $description = 'Simple ERP connectivity test (GET first 10 items)';

    public function handle(ERPClient $erp): int
    {
        try {
            $resource = (string) $this->argument('resource');
            $erp->smokeTest($resource);
            $this->info('ERP smoke OK for resource: ' . $resource);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('ERP smoke failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
