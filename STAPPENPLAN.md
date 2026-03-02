# Installatie / stappenplan (KMS GET commands)

1) Pak deze zip uit in de ROOT van je Laravel project (dus waar ook `artisan` staat).
   - Zorg dat de paden samenvallen: `app/`, `config/`, `routes/`, etc.

2) Controleer dat Artisan de commands ziet:
   ```bat
   php artisan list | findstr kms
   ```

3) Als je de commands NIET ziet:
   - Controleer `app/Console/Kernel.php`:
     er moet een regel bestaan die de Commands map laadt, bv:
     ```php
     protected function commands()
     {
         $this->load(__DIR__.'/Commands');
         require base_path('routes/console.php');
     }
     ```
   - Run daarna:
     ```bat
     composer dump-autoload
     php artisan optimize:clear
     php artisan list | findstr kms
     ```

4) Token test:
   ```bat
   php artisan kms:token:test
   ```

5) Alle GET tests (limit=5):
   zie `QUICK_COMMANDS.txt` (kopie/plak).
