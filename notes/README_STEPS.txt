CBsync patch v1.17 - next steps

WAT DIT PATCHJE DOET
1. Repareert/normaliseert kms:reverse:capabilities.
2. Haalt active/deleted uit de standaard testset zodat je niet opnieuw producten verstopt.
3. Voegt kms:repair:product-visibility toe om een product weer zichtbaar te maken na no-revert tests.
4. Registreert beide commands expliciet in app/Console/Kernel.php.

BELANGRIJKSTE HUIDIGE CONCLUSIE
- Voor BESTAANDE KMS-producten lijken updates beter te werken zonder type_number/type_name.
- Voor CREATE / eerste upsert lijkt type/familie nog steeds een apart vraagstuk.
- Jullie huidige blokkade lijkt nu vooral: sommige testartikelen zijn onzichtbaar geraakt door no-revert op active/deleted.

STAPPEN
1. Pak de zip uit over je projectroot heen.
2. Run:
   composer dump-autoload
   php artisan optimize:clear
   php artisan list | findstr kms:repair
   php artisan list | findstr kms:reverse

3. Probeer eerst een herstel op het PORTWEST-artikel:
   php artisan kms:repair:product-visibility 518060005020580 --ean=5036108217649 --unit=STK --brand=PORTWEST --color="royal blue" --size=XXL --price=89.95 --purchase-price=0 --vat=21 --amount=1 --supplier-name=PORTWEST --debug

4. Check of hij weer zichtbaar is:
   php artisan kms:debug:get-products --article-number=518060005020580 --limit=5 --debug
   php artisan kms:debug:get-products --ean=5036108217649 --limit=5 --debug

5. Als PORTWEST weer zichtbaar is, doe daarna capability test op een BESTAAND product:
   php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --debug --sleep=250

6. Doe daarna pas eventueel een expliciete destructive test, alleen als je dat bewust wilt:
   php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --fields=active,deleted --debug --sleep=250

7. Voor SIXTON / ERP->KMS sync doel:
   - Eerst bewijzen dat createUpdate een NIET-zichtbaar / niet-bestaand artikel kan opbouwen met alleen article/ean/unit/brand/color/size/price e.d.
   - Daarna pas familie-logica aanscherpen.

WAT JE MIJ HIERNA TERUGSTUURT
- Output van:
  php artisan kms:repair:product-visibility 518060005020580 --ean=5036108217649 --unit=STK --brand=PORTWEST --color="royal blue" --size=XXL --price=89.95 --purchase-price=0 --vat=21 --amount=1 --supplier-name=PORTWEST --debug
- Output van:
  php artisan kms:debug:get-products --article-number=518060005020580 --limit=5 --debug
- Output van:
  php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --debug --sleep=250

WAAR WE NU STAAN
- Reverse engineering bestaand product updategedrag: ongeveer 70-75%
- Familie/parent/create pad voor ERP -> KMS volle sync: ongeveer 45-55%
