# CBsync real-repo patch

Deze zip is gemaakt op basis van de actuele GitHub-root van `nveindhv/CBsync`:

- `app/Console/Commands`
- `app/Services/KmsClient.php`
- `app/Support/StoragePathResolver.php`
- `app/Console/Kernel.php` laadt automatisch alle commands uit `app/Console/Commands`

## Wat deze patch doet

1. **Herstelt een schone `app/Services/KmsClient.php`**
   - ondersteunt nu een optionele derde parameter `correlationId`
   - zet `X-Correlation-Id` header mee
   - behoudt het bestaande response-shape van `post()` (`ok`, `status`, `body`)

2. **Breidt `StoragePathResolver` uit**
   - `globAll()` om zowel `storage/app/...` als `storage/app/private/...` te doorzoeken
   - `ensurePrivateDir()` om report/seed directories veilig aan te maken

3. **Voegt twee nieuwe probe-commands toe**
   - `kms:prep:family-live-probe`
   - `kms:live:family-createupdate`

4. **Voorkomt fout-positieve successen**
   - success telt alleen als `created_now=true`
   - het report logt ook `changed_now`, `correlation_id`, `create_update_response`, `before`, `after`

5. **Vermijdt kapotte EAN-seeds**
   - nul-EANs zoals `00000000000000` worden niet automatisch meegestuurd
   - child/sibling selectie geeft voorkeur aan ERP-artikelen met bruikbare EAN

## Belangrijke context uit jullie bewijs

Jullie hebben al bewezen dat parent `505050117` zichtbaar kan zijn in KMS, inclusief naam, description, category, unit, brand en price.
Daarmee verschuift het doel van “kan een parent bestaan?” naar “onder welke family/basis-context worden missende varianten reproduceerbaar zichtbaar?”.

Deze patch is dus bedoeld voor precies dat vervolg:
- seed payloads bouwen uit scan-first dumps
- parent/child/sibling scenario’s draaien
- per stap hard vastleggen wat echt nieuw zichtbaar werd

## Gebruik

Pak deze zip uit op de **root** van jullie project en overschrijf de bestanden.
Gebruik daarna `docs/APPLY_COMMANDS.cmd`.
