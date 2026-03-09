composer dump-autoload
php artisan optimize:clear
php artisan erp:find:products --prefixes=505050367 --take=2000 --page-size=200 --offset=0
php artisan erp:families:combine --dump-json --debug
php artisan kms:scan:combine --dump-json --dump-csv --debug
php artisan kms:prep:family-live-probe 505050367 --child=505050367005440 --sibling=505050367005580 --write-json --debug
php artisan kms:probe:family-bootstrap-exhaustive 505050367 --children=505050367005440,505050367005580 --bases11=50505036700 --bases12=505050367005 --write-json --debug
