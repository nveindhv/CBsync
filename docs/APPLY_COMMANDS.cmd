composer dump-autoload
php artisan optimize:clear

REM Build / refresh scan-first inputs only if needed
REM php artisan kms:scan:products-window --offset=0 --limit=200 --max-pages=50 --dump-json --dump-csv --debug
REM php artisan kms:scan:combine --dump-json --dump-csv --debug
REM php artisan erp:find:products --prefixes=505050117,505050111,505050096 --take=800 --page-size=200 --offset=0
REM php artisan erp:families:combine --dump-json --debug
REM php artisan kms:build:parent-payload 505050117 --dump-json --debug

php artisan kms:prep:family-live-probe 505050117 --write-json --debug
php artisan kms:live:family-createupdate 505050117 --write-json --dry-run --debug
php artisan kms:live:family-createupdate 505050117 --write-json --debug --after-wait=3
php artisan kms:live:family-createupdate 505050117 --parent-only --write-json --debug --after-wait=3
