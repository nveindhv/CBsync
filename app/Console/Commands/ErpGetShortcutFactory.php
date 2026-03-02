<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Optional: creates runtime shortcut commands erp:get:{resource} without relying on Kernel::has/starting.
 *
 * NOTE: We do NOT auto-register these here (to avoid touching bootstrapping).
 * If your project already has a safe place to call this (e.g. your own command,
 * or a service provider you control), you can call ErpGetShortcutFactory::register().
 */
class ErpGetShortcutFactory
{
    public static function register(): void
    {
        $resources = (array) config('erp_gets.resources', []);
        if (empty($resources)) return;

        // Artisan::command uses Closure-based commands; safe across Laravel versions.
        foreach ($resources as $resource) {
            $resource = (string) $resource;
            $name = 'erp:get:' . $resource;

            // Register without checking existence; Laravel will throw if duplicate.
            Artisan::command($name . ' {--limit=200} {--offset=0} {--max-pages=1}', function () use ($resource) {
                $opts = [
                    '--limit' => $this->option('limit'),
                    '--offset' => $this->option('offset'),
                    '--max-pages' => $this->option('max-pages'),
                ];
                return Artisan::call('erp:get', array_merge(['endpoint' => $resource], $opts));
            })->purpose('Shortcut for erp:get ' . $resource);
        }
    }
}
