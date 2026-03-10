CBsync vervolgpatch – ERP -> KMS audit sync

Inhoud
- app/Console/Commands/SyncProductsToKmsAudit.php
- README_KMS_SYNC_AUDIT.txt

Doel
Deze command voert een ERP -> KMS productsync uit met:
- create/update payload per artikel
- logging van artikelnummer
- logging van ERP page, page_offset en index_in_page
- timestamps per poging en per verificatie
- GET-verificatie vóór/na op artikelnummer en EAN
- rapportage van artikelen die niet zichtbaar terugkomen via kms/product/getProducts

Belangrijk
- productclassifications worden hier bewust NIET gebruikt
- focus is puur: payload sturen, daarna GET-verifiëren
- alle niet-verifieerbare artikelen komen terug in auditbestanden

Plaatsen
1. Kopieer app/Console/Commands/SyncProductsToKmsAudit.php naar:
   app/Console/Commands/SyncProductsToKmsAudit.php
2. composer dump-autoload
3. php artisan optimize:clear

Output
Bij --write-json schrijft de command naar:
storage/app/private/kms_sync_audit/<run_id>/

Bestanden:
- summary.json
- audit.json
- audit.ndjson
- audit.csv
- per_article/<article>.json   (alleen met --dump-payloads)

Belangrijkste statuswaarden
- verified_visible
- write_success_not_visible
- write_exception
- skipped_invalid_article
- skipped_missing_name
- skipped_contains

Aanbevolen commands

1) Kleine dry-run op bekende subset
php artisan sync:products:kms-audit --only-codes=505050367005440,505050367005580 --target=2 --write-json --dump-payloads --debug

2) Live test op kleine subset
php artisan sync:products:kms-audit --only-codes=505050367005440,505050367005580 --target=2 --live --write-json --dump-payloads --debug

3) Live test met 3 seconden wachttijd vóór GET-after
php artisan sync:products:kms-audit --only-codes=505050367005440,505050367005580 --target=2 --live --after-wait=3 --write-json --dump-payloads --debug

4) Scan ERP paging-mode vanaf offset 0
php artisan sync:products:kms-audit --offset=0 --page-size=200 --max-pages=25 --target=250 --write-json --dump-payloads

5) Zelfde, maar filter op tekst
php artisan sync:products:kms-audit --contains=106101 --offset=0 --page-size=200 --max-pages=25 --target=250 --write-json --dump-payloads

Waar je vooral naar kijkt
- audit.csv: snel overzicht per artikel
- summary.json: totalen
- per_article/*.json: exacte payload + write_response + before/after GET

Wat je hiermee direct oplost
- je ziet per artikel WAAR het ERP-record stond
- je ziet of createUpdate technisch gelukt leek
- je ziet of het artikel daarna echt vindbaar was via GET
- je hebt achteraf een harde lijst van alle mislukte of onzichtbare artikelen
