# CBsync hotfix v1.12 (KMS reverse scan defaults + next steps)

## Wat zit hierin
- `app/Console/Commands/KmsReverseScan.php`
  - Default createUpdate endpoint is nu **`kms/product/createUpdate`**
  - Alt endpoint is **`kms/product/createUpdateProducts`**

Dit sluit aan op jullie laatste meting: `createUpdate` werkt en `createUpdateProducts` is bij jullie vaak 404 / of wordt genegeerd.

## Installeren (Windows / PowerShell)
Zorg dat je in de project-root staat (waar `artisan` staat), en run:

```powershell
Expand-Archive -Path .\CBsync_v1.12_kms_reverse_defaults.zip -DestinationPath . -Force
```

Daarna:

```powershell
php artisan optimize:clear
```

## Verifiëren
Run:

```powershell
php artisan kms:reverse:scan --offset=0 --scan=2000 --page-size=200 --max-families=50 --debug
```

Je hoeft **geen** `--createupdate-path=...` meer mee te geven; default is nu correct.

## Volgende stap (doel: ERP -> KMS sync)
De reverse scan heeft nu bewezen:
- Endpoint voor updates: **`kms/product/createUpdate`**
- In de meeste gevallen is **`type_number` niet nodig** om een product te updaten ("updateable_without_type").

### Wat we nu moeten bouwen/afmaken
1. **ERP productclassifications ophalen** (en caching lokaal)
2. **Beslisregel**: welke ERP-items moeten naar KMS (bv. WEB/U3 mapping)
3. **Upsert pipeline**: voor elk geselecteerd ERP-item een KMS `createUpdate` payload bouwen en posten
4. **Logging/reporting**: per item: UPDATED / IGNORED / ERROR + reden.
