<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\KmsReverseCapabilities::class,
        \App\Console\Commands\KmsReverseLayers::class,
        \App\Console\Commands\KmsReverseProduct::class,
        \App\Console\Commands\KmsReverseScan::class,
        \App\Console\Commands\KmsRepairProductVisibility::class,
        \App\Console\Commands\KmsProbeErpWindowSmart::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        //
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
