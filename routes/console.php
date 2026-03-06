<?php

use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Keep this file boring and safe.
| Do not call Artisan::starting() here; that broke command bootstrapping.
|
*/

Artisan::command('inspire', function () {
    $this->comment('Keep going.');
})->purpose('Display a small motivation line');
