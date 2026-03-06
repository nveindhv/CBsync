CBsync v1.20 - ERP SSL patch for kms:probe:erp-window

Probleem:
cURL error 60 / certificate hostname mismatch op api.comfortbest.nl:444

Wat deze patch doet:
- voegt --insecure toe aan kms:probe:erp-window
- respecteert ook ERP_VERIFY_SSL=false in .env
- zet verify=false alleen op de ERP HTTP call van deze command
- KMS calls blijven ongewijzigd

Stappen:
1. Pak de zip uit in je projectroot
2. Run:
   composer dump-autoload
   php artisan optimize:clear

Test eerst:
   php artisan kms:probe:erp-window --offset-from=88000 --offset-to=98000 --page-size=200 --insecure --debug

Daarna WEB-only:
   php artisan kms:probe:erp-window --offset-from=88000 --offset-to=98000 --page-size=200 --only-classification=WEB --insecure --debug

Als je het permanent voor ERP wilt:
   ERP_VERIFY_SSL=false

Belangrijk:
Dit is een tijdelijke workaround voor jullie test ERP endpoint/certificaat.
