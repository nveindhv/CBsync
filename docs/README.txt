KMS business fix patch v12

Inhoud
- SyncCustomersKms.php
- KmsBusinessEndpointSweep.php

Doel
- forceert KMS business requests naar de enige combinatie die in jullie sweep 200 gaf:
  - https://www.twensokms.nl/rest/democomfortbest/kms/business/list
  - https://www.twensokms.nl/rest/democomfortbest/kms/business/createUpdate
  - Authorization: Bearer <token>

Plaats de PHP files in:
- app/Console/Commands/

Vervolgcommands
1) composer dump-autoload
2) php artisan optimize:clear
3) php artisan kms:business:endpoint-sweep 00001 --name="Divers" --short-name="Divers" --debug --write-json
4) php artisan sync:customers:kms --erp-resource=organisations --limit=5 --live --write-json --dump-payloads --debug
5) php artisan sync:customers:kms --erp-resource=debtors --limit=5 --live --write-json --dump-payloads --debug

Verwachte check
- endpoint-sweep summary moet true tonen op list en write
- sync mag geen http_401 meer tonen
