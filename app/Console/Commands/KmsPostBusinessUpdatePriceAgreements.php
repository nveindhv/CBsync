<?php

namespace App\Console\Commands;

/**
 * Update price agreements (price agreements per customer) in KMS.
 *
 * Endpoint:
 *   POST kms/business/updatePriceAgreements
 */
class KmsPostBusinessUpdatePriceAgreements extends KmsBasePostCommand
{
    protected $signature = 'kms:post:price-agreements
        {--file= : Path to JSON payload file (recommended)}
        {--business-id= : KMS businessId}
        {--reference-id= : Customer referenceId (eigen systeem)}
        {--debtor-number= : Customer debtorNumber}
        {--debtorNumber= : Alias for --debtor-number}
        {--product-reference-id= : Product referenceId}
        {--article-number= : Product articleNumber}
        {--ean= : Product ean}
        {--price= : Fixed price excl.}
        {--percent= : Discount percent}
        {--sales-margin= : Sales margin factor}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: update price agreements for a business (kms/business/updatePriceAgreements)';

    protected function endpoint(): string
    {
        return 'kms/business/updatePriceAgreements';
    }

    protected function buildPayload(): array
    {
        // Allow alias
        $debtorNumber = $this->option('debtor-number') ?: $this->option('debtorNumber');

        $row = array_filter([
            'referenceId'   => $this->option('product-reference-id'),
            'articleNumber' => $this->option('article-number'),
            'ean'           => $this->option('ean'),
            'price'         => $this->option('price') !== null ? (float) $this->option('price') : null,
            'percent'       => $this->option('percent') !== null ? (float) $this->option('percent') : null,
            'salesMargin'   => $this->option('sales-margin') !== null ? (float) $this->option('sales-margin') : null,
        ], fn ($v) => $v !== null && $v !== '');

        $payload = array_filter([
            'businessId'   => $this->option('business-id') !== null ? (int) $this->option('business-id') : null,
            'referenceId'  => $this->option('reference-id'),
            'debtorNumber' => $debtorNumber,
        ], fn ($v) => $v !== null && $v !== '');

        $payload['priceAgreements'] = [$row];

        return $payload;
    }
}
