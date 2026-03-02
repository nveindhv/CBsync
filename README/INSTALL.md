# ERP GETs (add-only overlay)

This zip is **add-only**: it does **not** overwrite bootstrap files like `AppServiceProvider.php`, `routes/console.php`, or `Kernel.php`.

## Install
1. Unzip into your Laravel project root (merge folders).
2. Run:

```bash
composer dump-autoload
php artisan optimize:clear
```

## Configure allowed GET endpoints
Edit `config/erp_gets.php` and put the resources you are allowed to GET.

## Commands
List configured resources:
```bash
php artisan erp:resources
```

Run a GET (paged by offset/limit):
```bash
php artisan erp:get products --limit=50 --max-pages=1
php artisan erp:get products/106030168070420 --limit=1 --max-pages=1
```

Dumps are written to `storage/app/erp_dump/...`.

## ERP credentials
Uses your existing env/config keys:
- `ERP_BASE_URL`
- `ERP_API_BASE_PATH`
- `ERP_ADMIN`
- `ERP_USER`
- `ERP_PASS`

(Also supports `config('erp.*')` if your project already maps these.)
