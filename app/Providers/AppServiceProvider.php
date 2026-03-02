<?php

namespace App\Providers;

use App\Services\ERP\ErpApiClient;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind our ERP API client (safe: no named args, no Kernel::has, no command registration).
        $this->app->singleton(ErpApiClient::class, function ($app) {
            return new ErpApiClient(
                $app->make(HttpClient::class),
                (string) env('ERP_BASE_URL', ''),
                (string) env('ERP_API_BASE_PATH', ''),
                (string) env('ERP_ADMIN', '01'),
                (string) env('ERP_USER', ''),
                (string) env('ERP_PASS', ''),
            );
        });
    }

    public function boot(): void
    {
        // no-op
    }
}
