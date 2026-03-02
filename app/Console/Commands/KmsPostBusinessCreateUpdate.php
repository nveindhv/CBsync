<?php

namespace App\Console\Commands;

/**
 * Create or update a business (customer) in KMS.
 *
 * Endpoint:
 *   POST kms/business/createUpdate
 *
 * The KMS implementation guide describes this as a single-object payload.
 */
class KmsPostBusinessCreateUpdate extends KmsBasePostCommand
{
    protected $signature = 'kms:post:business
        {--file= : Path to JSON payload file (overrides other options)}
        {--id= : KMS business id (for update)}
        {--reference-id= : ReferenceId (eigen systeem)}
        {--debtor-number= : Debiteurnummer}
        {--name= : Naam}
        {--email= : Email}
        {--invoice-email= : Factuur email}
        {--phone= : Telefoon}
        {--street= : Straat}
        {--house-number= : Huisnummer}
        {--zip-code= : Postcode}
        {--city= : Plaats}
        {--remark= : Opmerking}
        {--dry-run : Print payload, do not call KMS}';

    protected $description = 'KMS: create/update business (kms/business/createUpdate)';

    protected function endpoint(): string
    {
        return 'kms/business/createUpdate';
    }

    protected function buildPayload(): array
    {
        return array_filter([
            'id'           => $this->option('id') !== null ? (int) $this->option('id') : null,
            'referenceId'  => $this->option('reference-id'),
            'debtorNumber' => $this->option('debtor-number'),
            'name'         => $this->option('name'),
            'email'        => $this->option('email'),
            'invoiceEmail' => $this->option('invoice-email'),
            'phone'        => $this->option('phone'),
            'street'       => $this->option('street'),
            'houseNumber'  => $this->option('house-number'),
            'zipCode'      => $this->option('zip-code'),
            'city'         => $this->option('city'),
            'remark'       => $this->option('remark'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
