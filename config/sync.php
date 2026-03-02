<?php

return [
    // false = sneller (geen read-back check na upsert)
    // true  = veiliger (na upsert checken of product echt bestaat/zichtbaar is)
    'verify_each_item' => env('SYNC_VERIFY_EACH_ITEM', false),

    // Als je ook SSL verify hier wil sturen (optioneel)
    'http_verify_ssl' => env('SYNC_HTTP_VERIFY_SSL', true),

    // (optioneel) basic tuning
    'batch_limit' => env('SYNC_BATCH_LIMIT', 50),
];
