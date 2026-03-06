<?php

use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is loaded by Laravel for Artisan console registration.
| We explicitly resolve the KMS reverse commands here as a fallback,
| because command discovery / Kernel registration has been inconsistent
| in this project.
|
*/

Artisan::starting(function ($artisan): void {
    $artisan->resolve(\App\Console\Commands\KmsReverseCapabilities::class);
    $artisan->resolve(\App\Console\Commands\KmsReverseScan::class);
    $artisan->resolve(\App\Console\Commands\KmsReverseLayers::class);
    $artisan->resolve(\App\Console\Commands\KmsReverseProduct::class);
});
