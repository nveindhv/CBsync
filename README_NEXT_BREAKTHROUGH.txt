NEXT BREAKTHROUGH PATCH

Wat dit toevoegt:
1. kms:inspect:family-shape
   - leest ALLE bestaande KMS scans
   - toont per family9 welke basis12-buckets, kleuren en maten echt voorkomen
   - ideaal voor 100010001 en andere families waar de basis niet exact via article lookup zichtbaar is

2. kms:probe:shortest-create-path
   - wrapper om de al BEWEZEN kms:probe:create-update flow te hergebruiken
   - test precies de kortste route:
     basis12 -> child variant -> sibling variant
   - gebruikt dus jullie nu bewezen create pad i.p.v. weer nieuwe ghost parent-logica

Belangrijkste interpretatie van jullie laatste output:
- variant create/update is WEL doorbroken
- parent/basis visibility is NOG NIET doorbroken
- family root 100010001 is scan-technisch interessant, maar niet direct getProducts(articleNumber=100010001)
- dus: focus op basis12-kandidaten uit scan, NIET blind op family9-root forceren
