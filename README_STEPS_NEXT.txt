CBsync v1.16 - command registration fix
=======================================

Doel
----
Deze patch forceert registratie van de KMS reverse commands,
zodat `kms:reverse:capabilities` weer zichtbaar wordt in Artisan.

Plaats deze bestanden over je projectroot.

Stappen
-------
1. Pak deze zip uit op je projectroot.

2. Run:
   composer dump-autoload
   php artisan optimize:clear
   php artisan list | findstr /I kms:reverse

3. Verwachte output:
   - kms:reverse:capabilities
   - kms:reverse:layers
   - kms:reverse:product
   - kms:reverse:scan

4. Test daarna direct op bestaand KMS-product:
   php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --no-revert --debug --sleep=250

Belangrijkste sync-conclusie tot nu toe
--------------------------------------
1. UPDATE van bestaande KMS-varianten:
   - stuur GEEN type_number/type_name mee
   - stuur WEL context mee:
     articleNumber/article_number, ean, unit, brand, color, size
   - daarna de velden die je wilt zetten, zoals:
     price, purchase_price, is_active, deleted, vAT, amount, supplierName

2. CREATE van nog niet-bestaande KMS-varianten:
   - gebruik createUpdate
   - stuur WEL type_number/type_name mee
   - family/type_number lijkt afleidbaar uit de eerste 11 tekens voor echte KMS families

3. Name lijkt tot nu toe niet updatebaar.

Praktische upsert-flow
----------------------
A. Zoek variant in KMS op articleNumber.
B. Bestaat hij:
   -> UPDATE zonder type_*.
C. Bestaat hij niet:
   -> CREATE met type_*.
D. Als ERP zegt inactive/deleted:
   -> probeer update met is_active=false en/of deleted=true.

Volgende test nadat capabilities werkt
--------------------------------------
Test exact deze 4 dingen op bestaand product:
1. price
2. purchase_price
3. is_active
4. deleted

En check daarna met:
   php artisan kms:debug:get-products --article-number=518060005020580 --limit=5 --debug

Als dat goed werkt, dan is de volgende echte bouwstap:
- sync command maken dat per ERP-product:
  * existence check doet in KMS
  * update zonder type_* doet
  * create met type_* doet
  * inactive/deleted uit ERP doorzet
