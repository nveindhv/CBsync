# CBsync root patch runbook

## Wat deze bundle plaatst

- `app/Services/Kms/KmsClient.php`
- `app/Support/StoragePathResolver.php`
- `app/Console/Commands/KmsPrepFamilyLiveProbe.php`
- `app/Console/Commands/KmsLiveFamilyCreateUpdate.php`

## Doel

Deze patchbundle doet vier dingen:

1. `KmsClient::post()` accepteert nu een optionele correlation id en stuurt die mee als `X-Correlation-Id`.
2. `kms:live:family-createupdate` ondersteunt `--parent-only` en probeert ontbrekende seed JSON automatisch te genereren.
3. Nieuwe command `kms:prep:family-live-probe` bouwt parent/child/sibling seed payloads uit bestaande storage dumps.
4. `StoragePathResolver` krijgt `globAll()` en verbeterde pad-resolutie zodat scan-first bestanden in `storage/app` én `storage/app/private` bruikbaar zijn.

## Verwachte outputbestanden

Na seed generation:

- `storage/app/private/kms_scan/live_family_probes/family_live_probe_<family>.json`
- `storage/app/private/kms_scan/live_family_probes/family_live_probe_<family>_parent_payload.json`
- `storage/app/private/kms_scan/live_family_probes/family_live_probe_<family>_child_payload.json`
- `storage/app/private/kms_scan/live_family_probes/family_live_probe_<family>_sibling_payload.json`

Na live family matrix:

- `storage/app/private/kms_scan/live_family_probes/family_create_matrix_<family>_<timestamp>.json`

## Minimale verificatie

Run na uitpakken:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan list | findstr kms:prep:family-live-probe
php artisan list | findstr kms:live:family-createupdate
```

## Aanbevolen volgorde

```bash
php artisan kms:scan:combine --dump-json --dump-csv --debug
php artisan erp:families:combine --dump-json --debug
php artisan kms:build:parent-payload 505050117 --dump-json --debug
php artisan kms:prep:family-live-probe 505050117 --write-json --debug
php artisan kms:live:family-createupdate 505050117 --write-json --dry-run --debug
php artisan kms:live:family-createupdate 505050117 --write-json --debug
php artisan kms:live:family-createupdate 505050117 --parent-only --write-json --debug
```

## Als de nieuwe command niet zichtbaar is

Gebruik eerst:

```bash
composer dump-autoload
php artisan optimize:clear
```

Als jullie project command discovery afwijkend heeft, controleer dan of jullie console bootstrap de classes in `app/Console/Commands` blijft laden.

## Veiligheid

- Plak geen access tokens of `.env` secrets terug.
- Doe eerst de `--dry-run` variant.
- Storage dumps kunnen klant- en productdata bevatten; commit die niet.
