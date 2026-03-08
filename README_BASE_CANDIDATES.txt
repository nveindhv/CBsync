CBsync next step - base/stamproduct candidate inspector

Bestanden:
- app/Console/Commands/ErpInspectBaseCandidates.php

Handmatig registreren in app/Console/Kernel.php:
    \App\Console\Commands\ErpInspectBaseCandidates::class,

Daarna:
    composer dump-autoload
    php artisan optimize:clear
    php artisan list | findstr erp:inspect:base-candidates

Aanbevolen workflow:

1) Maak of gebruik een bestaande ERP JSON dump:
   - bijvoorbeeld uit:
     php artisan erp:find:products --prefix=505050096 --take=200 --page-size=200 --offset=88000 -vvv
   - of:
     php artisan erp:find:products --prefix=505050111 --take=200 --page-size=200 --offset=88000 -vvv

2) Inspecteer de nieuwste dump:
   php artisan erp:inspect:base-candidates --debug

3) Inspecteer alleen een prefix:
   php artisan erp:inspect:base-candidates --prefix=505050096 --debug
   php artisan erp:inspect:base-candidates --prefix=505050111 --debug

4) Inspecteer een specifieke productomschrijving:
   php artisan erp:inspect:base-candidates --description="TRICORP 502006 (TKC2000) Short" --debug
   php artisan erp:inspect:base-candidates --description="TRICORP 502007 (TQC2000) Werkbroek" --debug

5) Dump het resultaat ook naar JSON:
   php artisan erp:inspect:base-candidates --prefix=505050096 --dump-json --debug
   php artisan erp:inspect:base-candidates --prefix=505050111 --dump-json --debug

Wat dit command doet:
- leest de nieuwste storage/app/erp_dump/prefix_matches_*.json (of een expliciet --json bestand)
- dedupliceert regels
- zoekt 'abnormale' / stam-achtige productcodes:
  * letters in productCode
  * veel nullen op het einde
  * patronen zoals EEN0000000
- groepeert ook op productomschrijving en family-prefixen
- laat snel zien of er kandidaten zijn voor basis/stamproducten die we daarna gericht in ERP/KMS kunnen testen
