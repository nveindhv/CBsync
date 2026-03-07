CBsync - kleine vervolgtest op basis van bestaande kms_probe_window scan
====================================================================

Doel
----
Niet opnieuw 10.000 ERP-regels scannen.
Wel: uit de AL bestaande window-scan een kleine, representatieve create/update subset halen,
zodat je gericht createUpdate gedrag op KMS kunt testen.

Bestand in deze zip
-------------------
- app/Console/Commands/KmsProbeWindowSamples.php

Wat deze command doet
---------------------
- pakt standaard de nieuwste CSV uit storage/app/kms_probe_window/window_*.csv
- filtert op create / update / classification
- maakt een kleine representatieve sample (standaard max 3 per merk)
- schrijft nieuwe bestanden weg:
  - storage/app/kms_probe_window/sample_*.csv
  - storage/app/kms_probe_window/sample_*.json
  - storage/app/kms_probe_window/sample_*_commands.txt  (als je --write-commands gebruikt)

Installatie
-----------
1) Pak deze zip uit in je projectroot.
2) Run:
   composer dump-autoload
   php artisan optimize:clear
3) Check of de command zichtbaar is:
   php artisan list | findstr kms:probe:window-samples

Aanbevolen gebruik
------------------
A. Maak eerst een kleine CREATE-sample uit je bestaande grote scan:
   php artisan kms:probe:window-samples --mode=create --limit=20 --per-brand=3 --write-commands --debug

B. Alleen WEB create-kandidaten uit diezelfde scan:
   php artisan kms:probe:window-samples --mode=create --only-classification=WEB --limit=20 --per-brand=3 --write-commands --debug

C. UPDATE-kandidaten:
   php artisan kms:probe:window-samples --mode=update --limit=20 --per-brand=3 --write-commands --debug

D. Op expliciete bron-CSV werken:
   php artisan kms:probe:window-samples --csv=storage/app/kms_probe_window/window_88000_98000_20260306_080630.csv --mode=create --limit=20 --write-commands --debug

Wat je daarna doet
------------------
Na een run krijg je een TXT-bestand met kant-en-klare commands.
Open die met bijvoorbeeld:
   type storage\app\kms_probe_window\sample_create_YYYYMMDD_HHMMSS_commands.txt

Voer daarna de eerste 3-10 commands uit.
- create-sample -> gebruikt kms:reverse:create-path
- update-sample -> gebruikt kms:probe:upsert-flow

Praktische volgorde
-------------------
1) Start met create zonder WEB-filter:
   php artisan kms:probe:window-samples --mode=create --limit=10 --per-brand=2 --write-commands --debug
2) Bekijk het command-bestand.
3) Run 3 tot 5 create-path tests.
4) Kijk welke payloads werken / niet werken.
5) Pas daarna pas WEB-filter toe als je classification mapping zekerder wilt testen.

Waarom dit handiger is
----------------------
- je gebruikt de al opgeslagen window-scan
- geen zware ERP-call opnieuw nodig
- je krijgt meteen een kleine, gemengde set testgevallen
- je kunt mislukte create-kandidaten veel sneller analyseren

Opmerking
---------
Deze command verandert niks in ERP of KMS. Hij leest alleen CSV en maakt samples + testcommands.
