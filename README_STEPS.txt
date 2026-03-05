Drop these files into your Laravel project (CBsync_v1 root), then run:

1) php artisan optimize:clear
2) php artisan list | findstr reverse

New command:
- php artisan kms:reverse:layers <VARIANT> --ean=<EAN> --prefix-min=4 --prefix-max=14 --debug
