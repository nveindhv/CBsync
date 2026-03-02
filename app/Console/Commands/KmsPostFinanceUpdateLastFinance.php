<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\File;

/**
 * Update finance flags in KMS using the last created finance id.
 *
 * Reads: storage/app/kms_state/last_finance_id.txt
 *
 * Endpoint:
 *   POST kms/finance/updateFinance
 */
class KmsPostFinanceUpdateLastFinance extends KmsBasePostCommand
{
    protected $signature = 'kms:post:finance-update-last
        {--exported=1 : 1/0 or true/false}
        {--referenceId= : referenceId}
        {--completed= : yyyy-mm-dd hh:ii:ss}
        {--paid= : yyyy-mm-dd hh:ii:ss}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: update finance using last created finance id (kms/finance/updateFinance)';

    protected function endpoint(): string
    {
        return 'kms/finance/updateFinance';
    }

    protected function buildPayload(): array
    {
        $path = storage_path('app/kms_state/last_finance_id.txt');
        if (!File::exists($path)) {
            $this->error("No last finance id found at: {$path}. Run kms:post:finance-order first.");
            return [];
        }
        $id = trim((string) File::get($path));

        $exported = $this->option('exported');
        if ($exported !== null) {
            if ($exported === '1' || $exported === 1 || $exported === true || $exported === 'true') {
                $exported = true;
            } elseif ($exported === '0' || $exported === 0 || $exported === false || $exported === 'false') {
                $exported = false;
            }
        }

        return array_filter([
            'id'          => (int) $id,
            'exported'    => $exported,
            'referenceId' => $this->option('referenceId'),
            'completed'   => $this->option('completed'),
            'paid'        => $this->option('paid'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
