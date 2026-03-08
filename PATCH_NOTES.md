# kms_probe_patch_next_v2

Deze patch doet 2 dingen:

1. `KmsProbeClientBridge` kan nu ook JSON-strings en single-row responses normaliseren.
2. De 3 probe-commands kunnen nu doorgaan met expliciete seedvelden als lookup op seed article/ean faalt.

Gebruik bij reducer/clone altijd expliciete seedvelden als het seed-artikel eerder wel zichtbaar was maar nu niet meer direct terugkomt via lookup.
