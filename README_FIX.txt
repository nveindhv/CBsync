Fix for Laravel/Symfony signature error:
- changed required/optional source argument into an option: --source=
- wrapper command now passes --source correctly

After unzip:
composer dump-autoload
php artisan optimize:clear

Use:
php artisan kms:compare:family-from-scan 505050117001400 505050117005400 505050117008400 505050117080400 505050117092400 --dump-json --dump-csv --debug

Optional explicit source:
php artisan kms:compare:family-from-scan 505050117001400 505050117005400 505050117008400 505050117080400 505050117092400 --source="C:\Users\NvL\PhpstormProjects\CBsync_v1\storage\app\private\kms_scan\products_window_20260307_025710.json" --dump-json --dump-csv --debug
