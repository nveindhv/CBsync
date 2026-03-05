<?php

return [
 /*
 |--------------------------------------------------------------------------
 | KMS API configuration
 |--------------------------------------------------------------------------
 |
 | Leading reference: project zip (code). The uploaded PDF is used as
 | supporting reference for URLs/paths.
 |
 */

 // Base host of the KMS platform (no trailing slash)
 // PDF examples use: https://www.twensokms.nl
 'base_url' => rtrim(env('KMS_BASE_URL', 'https://www.twensokms.nl'), '/'),

 // Namespace appears in the URL: /rest/{namespace}/...
 'namespace' => env('KMS_NAMESPACE', ''),

 // REST prefix template. Keep {namespace} placeholder.
 // PDF examples use: /rest/{namespace}
 'rest_prefix' => env('KMS_REST_PREFIX', '/rest/{namespace}'),

 // OAuth token path template. Keep {namespace} placeholder.
 // PDF examples use: /oauth/{namespace}/v2/token
 'token_path' => env('KMS_TOKEN_PATH', '/oauth/{namespace}/v2/token'),

 // Client credentials
 'client_id' => env('KMS_CLIENT_ID', ''),
 'client_secret' => env('KMS_CLIENT_SECRET', ''),

 // Resource owner credentials (if your KMS uses password grant)
 'username' => env('KMS_USER', ''),
 'password' => env('KMS_PASS', ''),

 // Request defaults
 'timeout' => env('KMS_TIMEOUT', 30),

 // Dumping
 'dump_enabled' => env('KMS_DUMP_ENABLED', true),
 'dump_dir' => env('KMS_DUMP_DIR', 'kms_dump'),

 // --- v1.9: Matrix update compatibility ---
 // Many "matrix" products require type_number/type_name to be present for UPDATES (createUpdate),
 // typically derived from the first N digits of the articleNumber. Your reverse engineering proved N=11 works.
 'family_len' => env('KMS_FAMILY_LEN', 11),

 // If we must invent a type_name, use this template.
 // {type_number} will be replaced with the derived value.
 'type_name_template' => env('KMS_TYPE_NAME_TEMPLATE', 'FAMILY {type_number}'),

 // Master switch: if false, no automatic type_* enrichment will happen in SyncProductsToKms.
 'sync_enrich_type_fields' => env('KMS_SYNC_ENRICH_TYPE_FIELDS', true),
];
