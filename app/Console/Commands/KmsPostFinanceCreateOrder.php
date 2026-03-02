<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\File;

/**
 * Create an order in KMS.
 *
 * Endpoint:
 *   POST kms/order/create
 */
class KmsPostFinanceCreateOrder extends KmsBasePostCommand
{
    protected $signature = 'kms:post:finance-order
        {--file= : Path to JSON payload file (recommended)}
        {--business-id= : KMS businessId}
        {--reference= : Order reference (free text)}
        {--comment= : Comment}
        {--send-to=pickup : pickup|send}
        {--row-ean= : Quick test: add 1 product row by ean}
        {--row-amount=1 : Quick test: amount}
        {--row-comment= : Quick test: row comment}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: create order (kms/order/create)';

    protected function endpoint(): string
    {
        return 'kms/order/create';
    }

    protected function buildPayload(): array
    {
        $products = [];
        if ($this->option('row-ean')) {
            $products[] = array_filter([
                'ean'     => $this->option('row-ean'),
                'amount'  => (int) $this->option('row-amount'),
                'comment' => $this->option('row-comment'),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return array_filter([
            'businessId' => $this->option('business-id') !== null ? (int) $this->option('business-id') : null,
            'reference'  => $this->option('reference'),
            'comment'    => $this->option('comment'),
            'sendTo'     => $this->option('send-to'),
            'products'   => $products ?: null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function afterResponse(array $response): void
    {
        // Save last created finance/order id for easy follow-up update calls
        if (!isset($response['id'])) {
            return;
        }
        $dir = storage_path('app/kms_state');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($dir . DIRECTORY_SEPARATOR . 'last_finance_id.txt', (string) $response['id']);
    }
}
