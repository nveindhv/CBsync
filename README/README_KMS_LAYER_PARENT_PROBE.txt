CBsync KMS layer/parent probe patch
===================================

Pak deze zip uit in je projectroot.

Bestanden
---------
- app/Console/Commands/KmsScanProductsWindow.php
- app/Console/Commands/KmsInspectLayers.php
- app/Console/Commands/KmsProbeHeadProduct.php
- app/Console/Commands/KmsInferParentFromVariant.php

Belangrijk
----------
De huidige Kernel in jullie repo laadt commands automatisch via:
    $this->load(__DIR__ . '/Commands');
Dus normaal hoef je niets handmatig te registreren.

Daarna run:
    composer dump-autoload
    php artisan optimize:clear
    php artisan list | findstr kms:scan:products-window
    php artisan list | findstr kms:inspect:layers
    php artisan list | findstr kms:probe:head-product
    php artisan list | findstr kms:infer:parent-from-variant

Aanbevolen testvolgorde
-----------------------
1) Grote KMS dump maken
    php artisan kms:scan:products-window --offset=0 --limit=200 --max-pages=50 --dump-json --dump-csv --debug

2) Die dump inspecteren op parent/family lagen
    php artisan kms:inspect:layers --dump-json --debug

3) Verdacht head/base artikel vergelijken met bekende variant
    php artisan kms:probe:head-product 505050096 --variant=505050096001660 --debug
    php artisan kms:probe:head-product 505050111 --variant=505050111001210 --debug
    php artisan kms:probe:head-product 505050117 --variant=505050117001400 --debug

4) Parent payload afleiden uit 1 variant + bekende siblings
    php artisan kms:infer:parent-from-variant 505050096001660 --siblings=505050096030660,505050096060660,505050096080660,505050096092660 --dump-json --debug
    php artisan kms:infer:parent-from-variant 505050111001210 --siblings=505050111060210,505050111080210 --dump-json --debug

Wat je nu probeert te bewijzen
------------------------------
- bestaan er kortere hoofdartikelen / parent-lagen in KMS zelf?
- welke velden komen alleen op parent/family records voor?
- kun je uit bestaande varianten een parent-payload reconstrueren?
- is parent/family create de ontbrekende stap vóór variant-create?

Output locaties
---------------
- storage/app/kms_scan/products_window_*.json
- storage/app/kms_scan/products_window_*.csv
- storage/app/kms_scan/layer_analysis_*.json
- storage/app/kms_scan/layer_analysis_*.csv
- storage/app/kms_scan/inferred_parent_*.json
