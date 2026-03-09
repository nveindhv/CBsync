Deze patch is gericht op de schone ERP family 505050367.

Bronnen:
- GERICHT technisch rapport voor CBsync KMS family bootstrap en schone nieuwe testdata.pdf
- Connector_ GitHub (repo nveindhv_CBsync).pdf

Doel:
- Niet opnieuw 505050117 / 505050111 / 505050096 testen.
- Wel de schone family 505050367 exhaustief testen.
- Expliciet 11-digit basisniveau meenemen, naast 9/12/15.

Aanbevolen child/sibling:
- child   = 505050367005440 (EAN 08718326569515)
- sibling = 505050367005580 (EAN 08718326569522)

Geteste lagen in het nieuwe command:
- 15 only
- 9 + 15
- 11 + 15
- 12 + 15
- 9 + 11 + 15
- 9 + 12 + 15
- 11 + 12 + 15
- 15 as self-parent

Belangrijke output:
storage\app\private\kms_scan\live_family_probes\exhaustive_family_probe_505050367_<timestamp>.json

Plak na de run vooral terug:
- console output van kms:probe:family-bootstrap-exhaustive
- of de JSON uit bovenstaand pad
