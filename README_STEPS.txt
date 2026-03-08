PATCH: succesvolle payload reducer

Wat dit doet
- Voegt 1 nieuwe command toe:
  php artisan kms:probe:successful-payload-reducer
- Doel: starten vanaf een al zichtbare / al bewezen create-shape en daarna veld-voor-veld reduceren.
- Hiermee zie je eindelijk zwart-op-wit welke velden echt nodig zijn voor een zichtbare create.

Belangrijkste antwoord op je vraag
- Nee: op basis van jullie huidige resultaten is nog NIET bewezen dat jullie een volledig nieuw product kunnen aanmaken waarvan de basis/familiestructuur nog niet bestaat.
- Wat wel bewezen is:
  1) createUpdate geeft vaak {"success":true}
  2) een nieuw sibling binnen een al bestaande echte familie kan zichtbaar worden (100010001099490)
  3) losse synthetische parent/basis tests worden nog niet zichtbaar
- Dus de kortste route is nu: reduceren vanaf de WEL-zichtbare create, niet opnieuw rondjes draaien op volledig synthetische parent-creaties.

Plaatsen
- Kopieer uit deze zip naar je Laravel root en overschrijf:
  app/Console/Commands/KmsProbeSuccessfulPayloadReducer.php

Daarna
1) composer dump-autoload
2) php artisan optimize:clear
3) controleer command:
   php artisan list | findstr kms:probe:successful-payload-reducer

Aanbevolen runs

A. Reducer op de echte succesvolle SIXTON-create
php artisan kms:probe:successful-payload-reducer 100010001099490 100010001099530 --seed-ean=8991000100019 --new-ean=8991000100057 --new-size=53 --write-json --debug --live

B. Nog een tweede run op dezelfde bewezen familie
php artisan kms:probe:successful-payload-reducer 100010001099490 100010001099540 --seed-ean=8991000100019 --new-ean=8991000100064 --new-size=54 --write-json --debug --live

C. Derde run voor bevestiging
php artisan kms:probe:successful-payload-reducer 100010001099490 100010001099550 --seed-ean=8991000100019 --new-ean=8991000100071 --new-size=55 --write-json --debug --live

Wat je moet lezen in de output
- baseline_success_shape = jullie huidige beste model
- drop_supplier_name = check of supplier echt nodig is
- drop_description = check of description echt nodig is
- drop_type_name / drop_type_number / drop_type_pair = check of type-relatie nodig is
- drop_color / drop_size / drop_brand / drop_unit = check welke productvelden essentieel zijn
- minimal_core = kortst logische payload
- article_ean_name_price_only = harde kale controlerun

Waar de JSON komt
- storage/app/private/kms_scan/live_family_probes/successful_payload_reducer_<artikel>.json

Interpretatie
- Als baseline zichtbaar is en een reductie ook zichtbaar blijft, dan is het verwijderde veld NIET verplicht.
- Zodra een scenario voor het eerst NIET zichtbaar wordt, zit je dicht bij de minimale werkende payload.
- Pas als we daarna met die minimale payload ook buiten de bestaande familie zichtbaar kunnen creëren, hebben we bewijs voor echt volledig nieuwe families/producten.
