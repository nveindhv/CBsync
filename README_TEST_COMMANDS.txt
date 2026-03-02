1) Unzip into project root (CBsync_v1)

2) Clear caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear

3) Confirm token works
php artisan kms:token:test

4) Run compare (scan ERP for matches)
php artisan compare:kms-erp:products --kms-limit=5 --kms-offset=0 --erp-start-offset=0 --erp-scan-limit=200 --erp-max-offset=120000 --erp-first5 --dump

Output JSON will be written to: storage/app/compare_dump/
