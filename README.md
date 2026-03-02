# KMS GETS (Laravel Artisan)

Deze zip bevat **drop-in Laravel bestanden** om alle *KMS “GET”* acties (die in de praktijk via **POST** gaan) werkend te maken, inclusief **dump naar storage/app/kms_dump/**.

De endpoints zijn overgenomen uit het KMS-document dat jij als referentie meegaf (en waar nodig afgestemd op de bron-implementatie die je al gebruikt in dit project).

## 1) Bestanden plaatsen
Kopieer de mappen uit deze zip naar jouw Laravel projectroot:

- `app/Console/Commands/*`
- `app/Services/Kms/*`
- `config/kms.php`

## 2) .env aanvullen
Zet dit in je `.env` (pas waarden aan):

```dotenv
# KMS
KMS_BASE_URL="https://kms.example.tld"   # basis URL van KMS
KMS_NAMESPACE="democomfortbest"         # jouw namespace
KMS_TOKEN_PATH="/token"                 # token endpoint
KMS_CLIENT_ID="..."
KMS_CLIENT_SECRET="..."
KMS_USER="..."
KMS_PASS="..."

# Dumping
KMS_DUMP_ENABLED=true
KMS_DUMP_DIR="kms_dump"
```

> Let op: token flow staat op `grant_type=password`.

## 3) Commands registreren
Open `app/Console/Kernel.php` en voeg in de `$commands` array toe:

```php
protected $commands = [
    \App\Console\Commands\KmsGetProducts::class,
    \App\Console\Commands\KmsGetBusinesses::class,
    \App\Console\Commands\KmsGetFinances::class,
    \App\Console\Commands\KmsGetFinance::class,
    \App\Console\Commands\KmsGetReturnGoods::class,
    \App\Console\Commands\KmsGetReturnStatuses::class,
    \App\Console\Commands\KmsGetStockProducts::class,
    \App\Console\Commands\KmsGetStockMutations::class,
];
```

## 4) Test: limit 5 van alles
Run vanuit je projectroot:

```bat
php artisan kms:get:products --limit=5 --max-pages=1
php artisan kms:get:businesses --limit=5 --max-pages=1
php artisan kms:get:finances --type=order --limit=5 --max-pages=1
php artisan kms:get:returngoods --all --limit=5 --max-pages=1
php artisan kms:get:return-statuses
php artisan kms:get:stock-products --limit=5 --max-pages=1
# stock-mutations heeft minimaal een datum of product-filter nodig
php artisan kms:get:stock-mutations --from=2025-01-01 --limit=5 --max-pages=1
```

**Dumpfiles** komen in:

- `storage/app/kms_dump/...`

Op Windows kun je ze openen met bijv.:

```bat
type storage\app\kms_dump\products\offset_0_limit_5.json
```

## 5) Filters (optioneel)
Sommige commands hebben extra opties:

- `kms:get:finances --type=order|invoice|credit --from=YYYY-MM-DD --to=YYYY-MM-DD`
- `kms:get:returngoods` verwacht minimaal 1 filter parameter:
  - `--all` (stuurt `exported=0`)
  - of `--exported=0|1`
  - of `--datetime="YYYY-MM-DD"` (key `dateTime`)
  - of `--type=business|location --id=<id>`
- `kms:get:stock-mutations`:
  - `--from=YYYY-MM-DD` (key `dateTime`)
  - of `--ean=...` / `--article-number=...`

Als jouw KMS sommige velden niet ondersteunt, kun je ze veilig weglaten (of uit de payload verwijderen in de command file).

---

### Over endpoints
Deze implementatie gebruikt deze paden (onder `/rest/{namespace}/`):

- `product/getProducts`
- `kms/business/list`
- `kms/finance/getFinances`
- `kms/finance/getFinance`
- `kms/returngoods/index`
- `kms/returngoods/getReturnStatuses`
- `kms/stock/getProducts`
- `kms/stock/mutations`


## Token test

```bash
php artisan kms:token:test
```
