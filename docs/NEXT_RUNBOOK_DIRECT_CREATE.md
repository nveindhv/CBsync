# CBsync vervolgstap — directe createUpdate probe volgens documentatie

## Kern van de documentatie
- `name` meesturen => als artikel nog niet bestaat, mag KMS het product toevoegen.
- `type_number` / familie-nummer is **aan te raden** bij toevoegen zodat maten/kleuren onder dezelfde family blijven.
- Stuur alleen velden die je echt wilt zetten.
- `products` array is verplicht.
- Zoekvolgorde bij update: eerst `ean`, anders `article_number`.

## Nieuwe command
Bestand:
- `app/Console/Commands/KmsProbeDirectCreateMatrix.php`

Artisan naam:
- `kms:probe:direct-create-matrix`

## Wat deze probe test
Deze command forceert **niet** eerst een parent-model.
Hij test juist de documentatie-hypothese:
1. nieuw child met alleen `article_number + name`
2. nieuw child met `article_number + name + type_number`
3. nieuw child met `name + brand + unit + type_number`
4. nieuw child full recommended
5. twee nieuwe children onder dezelfde `type_number`
6. twee nieuwe children onder dezelfde `type_number` inclusief prijsvelden

## Aanbevolen eerste family
Gebruik een family die in ERP zit maar niet in KMS zichtbaar is.
Uit de recente bevindingen lijkt `505050367` de schoonste kandidaat.

## Commands
### 1. Autoload/cache verversen
```bat
composer dump-autoload
php artisan optimize:clear
```

### 2. Dry run op schone family
```bat
php artisan kms:probe:direct-create-matrix 505050367 --name="TRICORP 106101 T-shirt" --brand=TRICORP --unit=STK --color-a=navy --color-b=black --size-a=44 --size-b=46 --write-json --debug
```

### 3. Live run
```bat
php artisan kms:probe:direct-create-matrix 505050367 --name="TRICORP 106101 T-shirt" --brand=TRICORP --unit=STK --color-a=navy --color-b=black --size-a=44 --size-b=46 --live --write-json --debug
```

### 4. Variant met 3 sec wachttijd tussen post en after-lookup
```bat
php artisan kms:probe:direct-create-matrix 505050367 --name="TRICORP 106101 T-shirt" --brand=TRICORP --unit=STK --color-a=navy --color-b=black --size-a=44 --size-b=46 --after-wait=3 --live --write-json --debug
```

## Artikelnummers die deze probe standaard gebruikt
Voor `family9=505050367`:
- Child A: `505050367005044`
- Child B: `505050367008046`

Met synthetische EANs op basis van `--ean-prefix`.

## Wat je terug moet plakken
Plak alleen:
1. volledige output van de live run
2. pad van het JSON rapport
3. of `VISIBLE` ergens voorkwam
4. of de artikelen in KMS UI vindbaar zijn op:
   - `505050367005044`
   - `505050367008046`
   - eventueel op EAN

## Interpretatie
- Als `child_a_name_only` al zichtbaar wordt, dan is parent-first waarschijnlijk onnodig.
- Als pas `name + type_number` werkt, dan is `type_number` in praktijk essentieel.
- Als alleen `two_children_same_type9` werkt, dan accepteert KMS mogelijk pas family-groepering als er meer dan één child is.
- Als niets zichtbaar wordt maar `success:true` wel terugkomt, dan blijft het visibility/caching/lookup-vraagstuk open.
