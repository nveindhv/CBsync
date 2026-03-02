<?php

$str = 'discountGroups';
return [

    /*
    |--------------------------------------------------------------------------
    | ERP GET resources (hardcoded)
    |--------------------------------------------------------------------------
    |
    | This list should contain ONLY the ERP endpoints you are allowed to GET.
    | (Based on your authorization schema: GET = Ja)
    |
    */

    'resources' => [
        'addressTypes',
        'attributeTypes',
        'batchJobs',
        'binaryDataAttachments',
        'branches',
        'countries',
        'currencies',
        'debtors',
        'deliveryConditions',
        '' . $str . '',
        'invoiceTypes',
        'languages',
        'organisations',
        'paymentConditions',
        'persons',
        'priceLists',
        'products',
        // Product classifications (toegevoegd voor testen / sync ERP -> KMS)
        'productClassifications',
        // Sommige omgevingen hebben ook een enkelvoudig endpoint
        'productClassification',
        'salesOrders',
        'units',
    ],

];
