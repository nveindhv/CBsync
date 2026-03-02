<?php

namespace App\Console\Commands;

use App\Services\ERP\ERPClient;
use App\Services\KMS\KMSClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SyncSmokeCommand extends Command
{
    protected $signature = 'sync:smoke';
    protected $description = 'Smoke test: ERP GET products + KMS token + KMS getProducts';

    public function handle(ERPClient $erp, KMSClient $kms): int
    {
        $correlationId = (string) Str::uuid();

        logger()->info('[SMOKE_START]', [
            'correlation_id' => $correlationId,
        ]);

        try {
            $erp->smokeTest($correlationId); // jouw ERP kant lijkt al oké nu
            $kms->smokeTest($correlationId);

            logger()->info('[SMOKE_COMPLETE]', [
                'correlation_id' => $correlationId,
                'status' => 'OK',
            ]);

            $this->info("Smoke OK. correlation_id={$correlationId}");
            return self::SUCCESS;
        } catch (Throwable $e) {
            logger()->error('[SMOKE_FAILED]', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->error("Smoke FAILED. correlation_id={$correlationId} error={$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
