<?php

return [

    'erp' => [
        'base_url' => env('ERP_BASE_URL', ''),
        'admin' => env('ERP_ADMIN', '01'),

        // Header token/auth (jij gebruikt Authorization: *** in logs)
        'auth_header' => env('ERP_AUTH_HEADER', 'Authorization'),
        'auth_value' => env('ERP_AUTH_VALUE', ''),

        // Endpoint path template
        'products_path' => env('ERP_PRODUCTS_PATH', '/rest/api/v1/{admin}/products'),
    ],

    'kms' => [
        'base_url' => env('KMS_BASE_URL', ''),
        'namespace' => env('KMS_NAMESPACE', 'democomfortbest'),

        // Jullie voorbeeld: /rest/{namespace}/kms/...
        'rest_base_template' => env('KMS_REST_BASE_TEMPLATE', '/rest/{namespace}'),

        'oauth' => [
            'token_url' => env('KMS_TOKEN_URL', ''),
            'client_id' => env('KMS_CLIENT_ID', ''),
            'client_secret' => env('KMS_CLIENT_SECRET', ''),
            'refresh_token' => env('KMS_REFRESH_TOKEN', ''),
        ],
    ],

    'sync' => [
        // defaults
        'batch_size' => (int) env('SYNC_BATCH_SIZE', 50),
        'max_items' => (int) env('SYNC_MAX_ITEMS', 200),
        'verify_each_item' => (bool) env('SYNC_VERIFY_EACH_ITEM', false),

        // Belangrijk bij jullie ERP met veel ean=0:
        // hoeveel ERP records je mag scannen om genoeg valide EANs te vinden
        'erp_scan_limit' => (int) env('SYNC_ERP_SCAN_LIMIT', 1000),

        // Logging: geen per-item spam.
        // Als true: logt max N voorbeeld-items per batch (sample).
        'log_item_samples' => (bool) env('SYNC_LOG_ITEM_SAMPLES', false),
        'log_item_sample_size' => (int) env('SYNC_LOG_ITEM_SAMPLE_SIZE', 10),

        // Payload snippet logging (HTTP response snippet etc.)
        'log_payload_snippets' => (bool) env('SYNC_LOG_PAYLOAD_SNIPPETS', false),
        'payload_snippet_bytes' => (int) env('SYNC_PAYLOAD_SNIPPET_BYTES', 800),
    ],
];
