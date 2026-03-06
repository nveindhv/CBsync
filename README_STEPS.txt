CBsync v1.17 - bewezen upsert-flow probe
=======================================

INHOUD
- app/Console/Commands/KmsReverseCreatePath.php
- app/Console/Commands/KmsProbeUpsertFlow.php
- docs/KMS_UPSERT_FLOW_NOTES.md
- README_STEPS.txt

DOEL
Deze patch voegt 2 commands toe:
1. kms:reverse:create-path
   Test welke createUpdate payload een MISSEND artikel zichtbaar maakt in KMS.
2. kms:probe:upsert-flow
   Test de praktische ERP -> KMS flow:
   - bestaat artikel al? => update zonder type
   - bestaat artikel niet? => create met type + name + context

STAPPEN
1) Pak de zip uit in je projectroot.
2) Run:
   composer dump-autoload
   php artisan optimize:clear
3) Check of commands bestaan:
   php artisan list | findstr kms:reverse:create-path
   php artisan list | findstr kms:probe:upsert-flow

BEWEZEN TESTS
A) Missing artikel -> create path
php artisan kms:reverse:create-path 100010001099400 --ean=8054085183839 --unit=PAAR --brand=SIXTON --color=taupe --size=40 --price=70.95 --purchase-price=0.11 --supplier-name=SIXTON --name="SIXTON NISIDA 10037-13, composiet neus + anti-perforatiezool (S3)" --debug

Verwachte interpretatie:
- minimal_price_only => niet zichtbaar
- context_no_type => niet zichtbaar
- context_with_name_no_type => niet zichtbaar
- context_with_type => niet zichtbaar
- context_with_type_and_name => zichtbaar

B) Praktische upsert flow op missing artikel
php artisan kms:probe:upsert-flow 100010001099400 --ean=8054085183839 --unit=PAAR --brand=SIXTON --color=taupe --size=40 --price=70.95 --purchase-price=0.11 --supplier-name=SIXTON --name="SIXTON NISIDA 10037-13, composiet neus + anti-perforatiezool (S3)" --debug

Verwacht:
- als artikel nog mist => CREATE path
- als artikel al zichtbaar is => UPDATE path

C) Praktische upsert flow op bestaand artikel
php artisan kms:probe:upsert-flow 518060005020580 --ean=5036108217649 --unit=STK --brand=PORTWEST --color="royal blue" --size=XXL --price=89.95 --purchase-price=0.11 --supplier-name=PORTWEST --debug

Verwacht:
- Existing product found in KMS -> UPDATE path
- payload zonder type_number/type_name
- artikel blijft zichtbaar

HUIDIGE WERKHYPOTHESE
- UPDATE bestaand KMS artikel:
  stuur GEEN type_number/type_name mee
- CREATE nieuw KMS artikel:
  stuur WEL mee:
  type_number/typeNumber + type_name/typeName + name + context
- family/type afleiding:
  voorlopig eerste 11 chars van articleNumber

WAT HIERNA BOUWEN
Volgende logische stap is een echte service/command voor productie:
- lookup articleNumber in KMS
- hit => update payload zonder type
- miss => create payload met type + name + context
- duidelijke logging per artikel
