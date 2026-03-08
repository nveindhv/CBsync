composer dump-autoload
php artisan optimize:clear

REM 1) Bouw nette child/sibling seeds met echte EANs waar mogelijk
php artisan kms:prep:family-live-probe 505050117 --child=505050117005440 --sibling=505050117008400 --write-json --debug

REM 2) Draai de bestaande matrix nog steeds als controle
php artisan kms:live:family-createupdate 505050117 --write-json --dry-run --debug
php artisan kms:live:family-createupdate 505050117 --write-json --debug

REM 3) Draai nu de uitputtende bootstrap probe: 9/12/9+12/15 combinaties
php artisan kms:probe:family-bootstrap-exhaustive 505050117 --children=505050117005440,505050117008400 --write-json --debug
