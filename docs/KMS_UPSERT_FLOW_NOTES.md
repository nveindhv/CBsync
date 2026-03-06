# KMS upsert flow notes

## Bewezen gedrag

### 1. Update op bestaand KMS artikel
Werkt met payload zonder `type_number` / `type_name`.

Praktisch patroon:
- `article_number` / `articleNumber`
- `ean`
- `unit`
- `brand`
- `color`
- `size`
- `price`
- `purchase_price`
- `supplier_name`
- optioneel `name`, maar naam lijkt niet te muteren

### 2. Create op missend KMS artikel
Werkende recipe:
- context
- `name`
- `type_number` / `typeNumber`
- `type_name` / `typeName`

Dus create is strenger dan update.

## Velden die tot nu toe werkten in update-path
- `price`
- `purchase_price`
- `unit`
- `brand`
- `color`
- `size`
- `supplier_name`

## Velden die tot nu toe niet overtuigend updatebaar lijken via dit pad
- `name`
- `vAT`
- `amount`

## Praktische sync-beslisboom
1. Zoek product in KMS op `articleNumber`
2. Bestaat het?
   - ja -> update zonder type
   - nee -> create met type + name + context

## Voorlopige family-afleiding
- `type_number = substr(articleNumber, 0, 11)`
- `type_name = 'FAMILY ' . type_number`

Dat is nog een werkhypothese, maar wel een bewezen werkende voor ten minste de huidige create-case.
