composer dump-autoload
php artisan optimize:clear
php artisan kms:prep:family-live-probe 505050117 --write-json --debug
php artisan kms:live:family-createupdate 505050117 --write-json --dry-run --debug
php artisan kms:live:family-createupdate 505050117 --write-json --debug
php artisan kms:live:family-createupdate 505050117 --parent-only --write-json --debug
