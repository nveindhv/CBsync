<?php

namespace App\Console;

use App\Console\Commands\SyncProducts;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        SyncProducts::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // intentionally empty (out of scope)
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
