<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ErpResourcesCommand extends Command
{
    protected $signature = 'erp:resources';
    protected $description = 'List hardcoded ERP resources that this project can GET (config/erp_gets.php)';

    public function handle(): int
    {
        $resources = (array) config('erp_gets.resources', []);

        if (empty($resources)) {
            $this->warn('No resources configured. Add them to config/erp_gets.php (resources array).');
            return self::SUCCESS;
        }

        foreach ($resources as $r) {
            $this->line((string) $r);
        }

        return self::SUCCESS;
    }
}
