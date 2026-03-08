1. Pak deze zip uit in de project root van CBsync.
2. Overschrijf de bestaande bestanden.
3. Run daarna:

composer dump-autoload
php artisan optimize:clear
php artisan list | findstr kms:reverse

4. Verwacht resultaat:
   kms:reverse:capabilities moet nu zichtbaar zijn in de lijst.

5. Test daarna direct op een bestaand KMS-product:

php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --no-revert --debug --sleep=250

6. Verwacht patroon:
   - updates werken op bestaand product
   - update payload zonder type_number/type_name
   - context velden wel meesturen: unit, brand, color, size

7. Sync-richting vanaf nu:
   - bestaat product in KMS -> update zonder type_*
   - bestaat product niet in KMS -> create met type_*
