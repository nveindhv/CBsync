ERP GET runner (hardcoded list)

Install:
- Unzip over project root (overwrite).
- Run: composer dump-autoload
- Run: php artisan optimize:clear

Usage:
- php artisan erp:resources
- php artisan erp:get products --limit=50 --max-pages=1
- php artisan erp:get products/106030168070420
- php artisan erp:get:products --limit=50 --max-pages=1

Config:
- config/erp_gets.php -> hardcoded resources list.
- .env uses ERP_BASE_URL, ERP_API_BASE_PATH, ERP_ADMIN, ERP_USER, ERP_PASS

Output:
- storage/app/erp_dump/{resource}/offset_{offset}_limit_{limit}.json
