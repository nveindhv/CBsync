DROP-IN PATCH: KMS parent/basis reverse engineering

Doel:
- Kortste route naar doorbraak voor hoofdproduct / basisartikel create
- Werkt met een schone synthetische testfamilie zodat je geen bestaande artikelen vervuilt
- Probeert parent(9), basis(12) en varianten in meerdere combinaties
- Controleert direct na elke createUpdate of het artikel zichtbaar wordt in KMS

Bestanden in deze zip:
- app/Console/Commands/KmsProbeParentMatrix.php
- app/Console/Kernel_addition.txt

Belangrijk:
- Deze command gebruikt App\Services\Kms\KmsClient en verwacht dat die al werkt
- Dry run post niets; live mode post wel echt naar KMS
- Gebruik eerst dry run, daarna live

Aanpak:
1) Kopieer app/Console/Commands/KmsProbeParentMatrix.php naar je project
2) Voeg de command class toe in app/Console/Kernel.php
3) Run composer dump-autoload
4) Run php artisan optimize:clear

Aanbevolen commando's:

Dry run met auto testfamilie:
php artisan kms:probe:parent-matrix --write-json --debug

Live met auto testfamilie:
php artisan kms:probe:parent-matrix --live --write-json --debug

Dry run met vaste familie 9 cijfers:
php artisan kms:probe:parent-matrix 999123456 --write-json --debug

Live met vaste familie 9 cijfers:
php artisan kms:probe:parent-matrix 999123456 --live --write-json --debug

Custom naam/kleuren/maten:
php artisan kms:probe:parent-matrix 999123456 --name="TEST SOFTSHELL" --brand=TRICORP --unit=STK --color-a="navy donkerblauw" --color-b="khaki kaki" --size-a=42 --size-b=44 --live --write-json --debug

Wat je moet terugsturen:
- de volledige console-output
- het pad van REPORT JSON
- vooral welke artikelen voor het eerst VISIBLE werden

Waar je op let:
- Als parent9 zichtbaar wordt -> parent kan direct als 9-cijferig hoofdproduct bestaan
- Als basis12 zichtbaar wordt -> 12-cijferig kleur-artikel zonder maat kan direct bestaan
- Als alleen varianten zichtbaar worden -> matrix hangt alleen aan type_number/type_name en niet aan apart parentrecord
- Als parent/basis pas zichtbaar worden in combinatie met varianten -> dan is sequence belangrijk
