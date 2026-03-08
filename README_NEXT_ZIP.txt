KMS FAMILY PATCH - FALLBACK / OFFLINE COMPARE
============================================

Waarom deze patch:
- Jullie hebben bewezen dat sommige artikelen WEL in een KMS window dump zitten,
  maar NIET via een directe articleNumber lookup terugkomen.
- Daardoor faalde kms:compare:family, ook voor bestaande varianten.
- Deze patch vergelijkt families daarom direct vanuit storage/app/private/kms_scan/products_window_*.json.

Bestanden:
- app/Console/Commands/KmsCompareFamilyFromScan.php
- app/Console/Commands/KmsCompareFamily.php   (fallback wrapper)

Na uitpakken:
1. composer dump-autoload
2. php artisan optimize:clear
3. registreer zo nodig in app/Console/Kernel.php:
   \App\Console\Commands\KmsCompareFamily::class,
   \App\Console\Commands\KmsCompareFamilyFromScan::class,

Aanbevolen commands:

1) Vergelijk bewezen bestaande KMS family vanuit scan:
php artisan kms:compare:family-from-scan 505050117001400 505050117005400 505050117008400 505050117080400 505050117092400 --dump-json --dump-csv --debug

2) Zelfde via de oude commandnaam (valt nu automatisch terug op scan-based compare):
php artisan kms:compare:family 505050117001400 505050117005400 505050117008400 505050117080400 505050117092400 --dump-json --dump-csv --debug

3) Expliciete scanbron meegeven:
php artisan kms:compare:family-from-scan 505050117001400 505050117005400 505050117008400 505050117080400 505050117092400 "C:\Users\NvL\PhpstormProjects\CBsync_v1\storage\app\private\kms_scan\products_window_20260307_025710.json" --dump-json --dump-csv --debug

Wat je hiermee moet bewijzen:
- welke velden in een bestaande KMS family overal gelijk zijn
- welke velden variant-specifiek zijn (kleur/maat/ean/etc.)
- welke parent/head payload logisch lijkt als afgeleide van echte KMS families

Belangrijk:
- Dit lost NIET direct create op.
- Dit geeft wel de sterkste basis om daarna een echte 'create parent first, then variant' probe te bouwen.
