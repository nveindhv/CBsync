CBsync v1.17 - KMS registration + capabilities real fix

Deze patch doet 3 dingen:
1. Herstelt app/Console/Kernel.php zodat kms:reverse:capabilities altijd geregistreerd wordt.
2. Herstelt routes/console.php zodat er geen kapotte Artisan boot hook meer in zit.
3. Vervangt KmsReverseCapabilities.php door een schone versie met:
   - werkende fetchOne() lookup op articleNumber en ean
   - normalisatie van keyed getProducts responses
   - rich/no_type retry voor UPDATE tests
   - extra debug-output zodat je exact ziet wat getProducts teruggeeft

Belangrijke functionele lijn:
- UPDATE van bestaand KMS product: zonder type_number/type_name werkt meestal beter
- CREATE van nieuw product: type_number/type_name blijft waarschijnlijk nodig
