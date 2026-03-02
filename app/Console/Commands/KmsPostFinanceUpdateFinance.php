<?php

namespace App\Console\Commands;

/**
 * Update finance flags in KMS.
 *
 * Endpoint:
 *   POST kms/finance/updateFinance
 */
class KmsPostFinanceUpdateFinance extends KmsBasePostCommand
{
    protected $signature = 'kms:post:finance-update
        {--file= : Path to JSON payload file (recommended)}
        {--id= : Alias for finance id (preferred)}
        {--finance-id= : Finance id in KMS (legacy)}
        {--exported= : true/false or 1/0}
        {--referenceId= : referenceId}
        {--completed= : yyyy-mm-dd hh:ii:ss}
        {--paid= : yyyy-mm-dd hh:ii:ss}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: update finance (kms/finance/updateFinance)';

    protected function endpoint(): string
    {
        return 'kms/finance/updateFinance';
    }

    protected function buildPayload(): array
    {
        $id = $this->option('id') ?? $this->option('finance-id');
        $exported = $this->option('exported');

        // normalize exported
        if ($exported !== null) {
            if ($exported === '1' || $exported === 1 || $exported === true || $exported === 'true') {
                $exported = true;
            } elseif ($exported === '0' || $exported === 0 || $exported === false || $exported === 'false') {
                $exported = false;
            }
        }

        return array_filter([
            'id'         => $id !== null ? (int) $id : null,
            'exported'   => $exported,
            'referenceId'=> $this->option('referenceId'),
            'completed'  => $this->option('completed'),
            'paid'       => $this->option('paid'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
