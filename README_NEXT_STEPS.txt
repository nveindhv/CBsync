CBsync patch - next step (family by product code structure)

What is in this zip
- app/Console/Commands/KmsReverseCreatePath.php
- app/Console/Commands/KmsProbeUpsertFlow.php

Why this patch
- The uploaded implementation scan states the product code is structured as:
  brand + articlegroup + unique number + color code + size code.
- For your TRICORP examples this strongly suggests the family/base is the code BEFORE color+size.
- So this patch changes the practical default family derivation from 11 chars to 9 chars for create-path/upsert-flow.
- It also enriches CREATE payloads with brand/color/size/purchase_price/supplier_name when you provide them.

Important behavior after this patch
- Existing KMS article => update path WITHOUT type_number/type_name
- Missing KMS article => create path WITH type_number/type_name + richer context
- Default family-len is now 9 for these 2 commands

Install
1) Extract this zip into your Laravel project root.
2) Run:
   composer dump-autoload
   php artisan optimize:clear
3) Check commands still exist:
   php artisan list | findstr kms:reverse:create-path
   php artisan list | findstr kms:probe:upsert-flow

Recommended tests
A. TRICORP 502006 / article 505050096001660
php artisan kms:reverse:create-path 505050096001660 --ean=8718326525689 --unit=STK --name="TRICORP 502006 (TKC2000) Short" --brand=TRICORP --color=navy --size=66 --purchase-price=10.27 --type-number=505050096 --type-name="FAMILY 505050096" --debug

php artisan kms:probe:upsert-flow 505050096001660 --ean=8718326525689 --unit=STK --name="TRICORP 502006 (TKC2000) Short" --brand=TRICORP --color=navy --size=66 --purchase-price=10.27 --type-number=505050096 --type-name="FAMILY 505050096" --debug

B. TRICORP 502007 / article 505050111001210
php artisan kms:reverse:create-path 505050111001210 --ean=8718326525900 --unit=STK --name="TRICORP 502007 (TQC2000) Werkbroek" --brand=TRICORP --color=navy --size=21 --purchase-price=12.14 --type-number=505050111 --type-name="FAMILY 505050111" --debug

php artisan kms:probe:upsert-flow 505050111001210 --ean=8718326525900 --unit=STK --name="TRICORP 502007 (TQC2000) Werkbroek" --brand=TRICORP --color=navy --size=21 --purchase-price=12.14 --type-number=505050111 --type-name="FAMILY 505050111" --debug

If you want to test without forcing type_number/type_name, let the new default family-len=9 do it:
php artisan kms:reverse:create-path 505050096001660 --ean=8718326525689 --unit=STK --name="TRICORP 502006 (TKC2000) Short" --brand=TRICORP --color=navy --size=66 --purchase-price=10.27 --debug
php artisan kms:reverse:create-path 505050111001210 --ean=8718326525900 --unit=STK --name="TRICORP 502007 (TQC2000) Werkbroek" --brand=TRICORP --color=navy --size=21 --purchase-price=12.14 --debug

What success looks like
- create-path prints: VISIBLE ✔ via recipe=...
- upsert-flow prints either:
  - Existing product found in KMS -> UPDATE path
  - Missing product in KMS -> CREATE path
  and then VISIBLE ✔ after the step

If this still fails
- Then the family grouping is still not enough and we should make a second patch that derives color/size directly from ERP searchKeys or a dedicated ERP sampler command.
