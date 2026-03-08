CBsync v1.18 patch

Goal:
- restore canonical App\Services\Kms\* autoload path
- stop depending on app/Services/KMS casing mismatch
- replace broken/flattened KmsReverseCapabilities with a clean file

After unpacking:
1) composer dump-autoload
2) php artisan optimize:clear
3) php artisan list | findstr kms:reverse
4) php artisan kms:debug:get-products --article-number=518060005020580 --limit=5 --debug
5) php artisan kms:reverse:capabilities 518060005020580 --ean=5036108217649 --mode=rich --no-revert --debug --sleep=250

Important:
If you still see weird autoload behaviour, temporarily rename this old folder in Windows Explorer:
- app\Services\KMS  -> app\Services\KMS_OLD
That should not be needed anymore after this patch, but it removes the casing ambiguity completely.
