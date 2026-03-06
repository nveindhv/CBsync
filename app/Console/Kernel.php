<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Force-register important custom commands.
     * This is extra safety on top of $this->load(__DIR__.'/Commands').
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        \App\Console\Commands\KmsReverseCapabilities::class,
        \App\Console\Commands\KmsReverseScan::class,
        \App\Console\Commands\KmsReverseLayers::class,
        \App\Console\Commands\KmsReverseProduct::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        //
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
