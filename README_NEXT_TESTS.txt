CBsync next test patch

Deze patch voegt 3 commands toe:

1. php artisan kms:inspect:article-root 100010001 --debug
   - checkt direct lookup + combined KMS scan index
   - bedoeld om snel te zien of een root/family echt interessant is

2. php artisan kms:probe:visible-variant-requirements 505050117005400    --ean=8718326158665 --brand=TRICORP --unit=STK --color="ink donkerblauw" --size=XXS    --type-number=505050117 --type-name="TRICORP 402006 (TSJ2000) Softshell"    --name="TEST 505050117005400" --write-json --debug

   Zelfde voor:
   - 505050111001210 / ean 8718326525900 / color navy donkerblauw / size 21 / type 505050111
   - 505050096001660 / ean 8718326525689 / color navy donkerblauw / size 66 / type 505050096

   Doel:
   - exact zien welke velden nog steeds leiden tot zichtbaarheid
   - verschil tussen visible en success-but-ghost rapporteren

3. php artisan kms:probe:parent-from-visible 999123456 999123456001 999123456001042    --ean-a=8999991234568 --basis12b=999123456030 --variantB=999123456030042 --ean-b=8999991234569    --name="TEST PARENT FROM VISIBLE" --brand=TESTBRAND --unit=STK --color-a=navy --color-b=black    --size-a=42 --size-b=42 --write-json --debug

   Daarna live:
   php artisan kms:probe:parent-from-visible 999123456 999123456001 999123456001042    --ean-a=8999991234568 --basis12b=999123456030 --variantB=999123456030042 --ean-b=8999991234569    --name="TEST PARENT FROM VISIBLE" --brand=TESTBRAND --unit=STK --color-a=navy --color-b=black    --size-a=42 --size-b=42 --write-json --debug --live

Belangrijk:
- gebruik bij parent/basis probes verschillende EANs per variant; geen gedeelde EANs meer
- visibility blijft de enige waarheid, niet success=true
- als app/Console/Kernel.php load(__DIR__.'/Commands') gebruikt, is extra registratie niet nodig
