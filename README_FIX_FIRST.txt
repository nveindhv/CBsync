OVERWRITE THESE FILES INTO YOUR PROJECT ROOT.

This bundle fixes:
1) kms:scan:combine memory crash
2) wrong storage path preference (app/private chosen before app)
3) follow-up commands failing when combined indexes do not exist yet

Then run exactly:

composer dump-autoload
php artisan optimize:clear
php artisan erp:families:combine --dump-json --debug
php artisan kms:scan:combine --dump-json --dump-csv --debug
php artisan kms:map:erp-families --family=505050117 --dump-json --debug
php artisan kms:build:parent-payload 505050117 --dump-json --debug
php artisan kms:probe:family-bootstrap 505050117 --dump-json --debug
php artisan kms:map:erp-families --family=505050111 --dump-json --debug
php artisan kms:build:parent-payload 505050111 --dump-json --debug
php artisan kms:probe:family-bootstrap 505050111 --dump-json --debug
php artisan kms:map:erp-families --family=505050096 --dump-json --debug
php artisan kms:build:parent-payload 505050096 --dump-json --debug
php artisan kms:probe:family-bootstrap 505050096 --dump-json --debug
