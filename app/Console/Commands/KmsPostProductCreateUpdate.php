<?php

use App\Services\Kms\KmsPayloadEnricher;
/**
 * Create or update products in KMS (bulk).
 *
 * Endpoint:
 *   POST kms/product/createUpdate
 *
 * Docs fields are snake_case for createUpdate.
 */
class KmsPostProductCreateUpdate extends KmsBasePostCommand
{
    protected $signature = 'kms:post:product
        {--file= : Path to JSON payload file (overrides other options)}
        {--reference-id= : ReferenceId (eigen systeem)}
        {--article-number= : Artikelnummer}
        {--ean= : EAN / SKU}
        {--name= : Productnaam}
        {--description= : Omschrijving}
        {--price= : Verkoopprijs excl.}
        {--purchase-price= : Inkoopprijs}
        {--vat=21 : BTW percentage (optional)}
        {--is-active=1 : 1=actief, 0=inactief}
        {--is-deleted=0 : 1=deleted, 0=not deleted}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: create/update products (kms/product/createUpdate)';

    protected function endpoint(): string
    {
        return 'kms/product/createUpdate';
    }

    protected function buildPayload(): array
    {
        // Build 1-product payload from CLI.
        // KMS expects snake_case keys for createUpdate.
        $product = array_filter([
            'referenceId'     => $this->option('reference-id'),
            'article_number'  => $this->option('article-number'),
            'ean'             => $this->option('ean'),
            'name'            => $this->option('name'),
            'description'     => $this->option('description'),
            'price'           => $this->option('price') !== null ? (float) $this->option('price') : null,
            'purchase_price'  => $this->option('purchase-price') !== null ? (float) $this->option('purchase-price') : null,
            // KMS getProducts returns vAt; createUpdate field in docs is not strictly required.
            'vAt'             => $this->option('vat') !== null ? (int) $this->option('vat') : null,
            'is_active'       => $this->option('is-active') !== null ? (int) $this->option('is-active') : null,
            'is_deleted'      => $this->option('is-deleted') !== null ? (int) $this->option('is-deleted') : null,
        ], fn ($v) => $v !== null && $v !== '');

        return KmsPayloadEnricher::enrichCreateUpdatePayload([
            'products' => [$product],
        ], (int) config('kms.family_len', 11), (string) config('kms.type_name_template', 'FAMILY {type_number}'));
    }
}
