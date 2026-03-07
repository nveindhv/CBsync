KMS FAMILY BOOTSTRAP PATCH BUNDLE
=================================

Dit is een losse patchbundle voor CBsync. Niet automatisch toegepast.
Kopieer de bestanden handmatig in je projectroot.

DOEL
----
Deze bundle trekt het project weg van losse probes naar 1 scan-first familieflow:
1. KMS scans combineren
2. ERP family dumps combineren
3. ERP-families tegen KMS-scan mappen
4. Parent payload opbouwen
5. Family bootstrap probe draaien

BESTANDEN IN DEZE ZIP
---------------------
app/Support/StoragePathResolver.php
app/Console/Commands/KmsScanCombine.php
app/Console/Commands/ErpFamiliesCombine.php
app/Console/Commands/KmsMapErpFamilies.php
app/Console/Commands/KmsBuildParentPayload.php
app/Console/Commands/KmsProbeFamilyBootstrap.php

docs/COMMANDS_TO_RUN.txt
docs/WHY_THIS_BREAKS_THE_DEADLOCK.txt
docs/COPY_PASTE_SEQUENCE.txt

PLAATSEN
--------
Kopieer vanaf de zip-root naar je Laravel projectroot.

Daarna in app/Console/Kernel.php toevoegen aan $commands:
    \App\Console\Commands\KmsScanCombine::class,
    \App\Console\Commands\ErpFamiliesCombine::class,
    \App\Console\Commands\KmsMapErpFamilies::class,
    \App\Console\Commands\KmsBuildParentPayload::class,
    \App\Console\Commands\KmsProbeFamilyBootstrap::class,

BELANGRIJK
----------
Deze commands zijn bewust scan-first. Dus:
- primaire waarheid = scanbestanden op disk
- live KMS artikel-lookups zijn alleen aanvullend
- storage path mismatch private/public wordt afgevangen

SNELLE STAPPEN
--------------
1) composer dump-autoload
2) php artisan optimize:clear
3) php artisan kms:scan:combine --dump-json --dump-csv --debug
4) php artisan erp:families:combine --dump-json --debug
5) php artisan kms:map:erp-families --family=505050117 --dump-json --debug
6) php artisan kms:build:parent-payload 505050117 --dump-json --debug
7) php artisan kms:probe:family-bootstrap 505050117 --dump-json --debug

DAARNA HERHALEN VOOR:
- 505050111
- 505050096

WAT JE MOET VERWACHTEN
----------------------
- 505050117 is de beste eerste family omdat daarvan al minstens 1 KMS-variant gezien is.
- 505050111 en 505050096 lijken ERP-families die nog een parent/bootstrapstap nodig kunnen hebben.
- Als parent bootstrap faalt, dan heb je eindelijk 1 centrale plek waar de failure zwart-op-wit uitgelogd wordt.
