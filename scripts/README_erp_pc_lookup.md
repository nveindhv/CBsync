# ERP productClassifications lookup (artikel -> categorie)

Doel: **voor specifieke artikel nummers** opzoeken welke `productCategoryCode` ze hebben in ERP `productClassifications` (bv. `WEB`).

## 1) Vul je artikelenlijst

Maak een bestand aan (of kopieer sample):

- `storage/app/erp_samples/product_codes.txt`

Formaat: 1 productCode per regel, of een JSON array.

## 2) Run via npm/node

Voorbeeld (direct):

```bash
node scripts/erp_pc_lookup.js --file=storage/app/erp_samples/product_codes.txt --limit=200 --max-pages=50
```

Of snel met inline list:

```bash
node scripts/erp_pc_lookup.js --products=106030168070420,500050065080560 --limit=200 --max-pages=50
```

Tip: wil je 'm als npm script, zet dit in je `package.json`:

```json
{
  "scripts": {
    "erp:pc:lookup": "node scripts/erp_pc_lookup.js --file=storage/app/erp_samples/product_codes.txt"
  }
}
```

## Output

- Print JSON met `found` en `missingProductCodes`
- Dump (standaard) naar: `storage/app/erp_dump/productClassifications_lookup/lookup_YYYYmmdd_HHMMSS.json`
