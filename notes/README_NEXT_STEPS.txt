CBsync v1.19 - KMS upsert flow probe

Doel
- Praktische ERP -> KMS flow testen:
  1) bestaand artikel => UPDATE zonder type_number/type_name
  2) missend artikel => CREATE met type + name + context
  3) daarna nog een UPDATE zonder type als steady-state syncpad

Belangrijkste bewezen patroon tot nu toe
- CREATE / zichtbaar maken van missend artikel werkt met:
  articleNumber/article_number + ean + unit + brand + color + size + name +
  price + purchase_price + supplier_name + type_number/typeNumber + type_name/typeName
- UPDATE van bestaand artikel werkt juist beter zonder type_*
- purchase_price werkt, purchasePrice meestal niet
- supplier_name werkt, supplierName meestal niet
- name lijkt niet updatebaar
- vAT en amount lijken op jullie KMS meestal genegeerd in dit pad

Installatie
1. Pak de zip uit in de projectroot.
2. Voeg zonodig deze class toe aan app/Console/Kernel.php:
   \App\Console\Commands\KmsProbeUpsertFlow::class,
3. Run:
   composer dump-autoload
   php artisan optimize:clear
   php artisan list | findstr kms:probe:upsert-flow

Aanbevolen tests
A. Missend artikel opnieuw probe'en
php artisan kms:probe:upsert-flow 100010001099400 --ean=8054085183839 --unit=PAAR --brand=SIXTON --color=taupe --size=40 --price=70.95 --purchase-price=0.11 --supplier-name=SIXTON --name="SIXTON NISIDA 10037-13, composiet neus + anti-perforatiezool (S3)" --debug

B. Bestaand artikel updatepad probe'en
php artisan kms:probe:upsert-flow 518060005020580 --ean=5036108217649 --unit=STK --brand=PORTWEST --color="royal blue" --size=XXL --price=89.95 --purchase-price=0.11 --supplier-name=PORTWEST --debug

Interpretatie
- Als missend artikel zichtbaar wordt na create-step, dan is create-path bruikbaar voor sync.
- Als bestaand artikel zichtbaar blijft en waarden juist terugkomen, dan is update-path bruikbaar voor sync.
- Daarmee is de kern van ERP -> KMS variantsync in de praktijk bijna dicht.
