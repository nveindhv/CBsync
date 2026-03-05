ERP->KMS mapper / dump sync commands (v1.6)

Wat is er nieuw in v1.6
- kms:update:price-smart doet nu 2 stappen:
  1) MINIMAL payload (article + ean + price) -> verify
  2) Als dit genegeerd wordt door KMS, automatisch FULL_MATRIX payload (unit/brand/color/size + type_number/type_name) -> verify
  Dit voorkomt dat je “success:true” ziet terwijl price niet verandert.

Commands
- catalog:kms:sync-family-dump
  (zelfde als v1.5)

- kms:update:price-smart
  Usage:
    php artisan kms:update:price-smart <article> <price> --ean=<ean> --debug
  Opties:
    --force-full   : sla minimal over en ga direct full matrix
    --type-len=11  : family/type prefix lengte (default 11)
    --type-number  : override type_number
    --type-name    : override type_name
    --no-type      : stuur geen type_* (alleen gebruiken als je zeker weet dat KMS het accepteert)
    --dry-run      : build + verify, maar geen createUpdate call

Praktische next steps (reverse engineering)
1) Pak 3 families uit jullie analyzer output: FULL, PARTIAL, MISSING.
2) Voor elke familie:
   - family:dump (als je nog geen dump hebt) -> dump-json
   - catalog:kms:sync-family-dump --only-missing --erp-no-verify
3) Pak 1 variant per familie en test update gedrag:
   - kms:update:price-smart ... (kijkt meteen of minimal vs full nodig is)
4) Als createUpdate alleen werkt met type_number/type_name, dan is dat jullie “family layer” requirement.

