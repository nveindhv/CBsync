CBsync patch v1.19 - select fix for kms:probe:erp-window

Deze patch fixt de crash:
'Array to string conversion' op $this->option('select').

Wat is aangepast:
- signature veranderd van {--select=*} naar {--select=}
- defensive handling toegevoegd als select toch als array binnenkomt
- default blijft '*'

Stappen:
1. Pak deze zip uit in de projectroot
2. Run:
   composer dump-autoload
   php artisan optimize:clear
3. Test:
   php artisan kms:probe:erp-window --offset-from=88000 --offset-to=98000 --page-size=200 --debug

Optioneel:
   php artisan kms:probe:erp-window --offset-from=88000 --offset-to=98000 --page-size=200 --only-classification=WEB --debug
