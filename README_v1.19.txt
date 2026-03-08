CBsync v1.19 ERP window auth/client/retry fix

This patch replaces app/Console/Commands/KmsProbeErpWindow.php.

What changed
- Uses ERPClient for normal SSL-verified ERP requests.
- Uses explicit Basic auth + verify=false only when --insecure is passed.
- Adds ERP request timeout/connect-timeout/retries options.
- Logs connection exceptions into the JSON failure list instead of crashing immediately.

Suggested commands

composer dump-autoload
php artisan optimize:clear

Fast test:
php artisan kms:probe:erp-window --offset-from=88000 --offset-to=88200 --page-size=50 --insecure --timeout=120 --connect-timeout=20 --retries=1 --debug

WEB-only:
php artisan kms:probe:erp-window --offset-from=88000 --offset-to=88200 --page-size=50 --only-classification=WEB --insecure --timeout=120 --connect-timeout=20 --retries=1 --debug

If that works, expand:
php artisan kms:probe:erp-window --offset-from=88000 --offset-to=98000 --page-size=50 --only-classification=WEB --insecure --timeout=120 --connect-timeout=20 --retries=1 --debug
