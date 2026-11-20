# Documentation SymSvel : assemblage hors Setup

Le contenu documentaire est fourni par chaque composant et module sous
`docs/`. Pour les modules Composer, seules les sources explicitement
déclarées dans `module.json.documentation` (`dev`, `admin`, `user`)
sont intégrées. Les sources des hôtes Back, BO, App et Front présentes
dans le workspace contribuent au scope `dev`.

## Publication locale

Depuis un projet initialisé, exécuter :

```bash
symsvel documentation:sync
```

La CLI délègue à Tooling, qui publie un index **privé** dans
`backend/var/symsvel/documentation.json`. La publication ne nécessite
aucune base, aucune dépendance Python, aucun MkDocs ni build de frontend.
L'index ne doit pas être placé dans `public/`, `static/` ou `build/`.
Les changements de sources sont récupérés lors du prochain lancement de
cette commande, après l'installation, la mise à jour ou le retrait d'un module.

Chaque page a un identifiant stable `scope:source:owner:chemin.md`,
un titre, un propriétaire et son texte Markdown. Les documents de
développement doivent rester réservés au développeur ; les guides admin
ne sont accessibles qu'avec une identité BO valide via le module natif
responsable de l'exposition HTTP. Les rendus Svelte doivent traiter le
Markdown comme du **texte** et ne pas injecter de HTML non filtré.

## Protection et limites

La commande refuse les sources symboliques, les identifiants de chemin
dangereux, les documents dépassant 512 Kio et un corpus dépassant 12 Mio.
Elle publie atomiquement le fichier JSON en maintenant son checksum.
Une modification locale de l'artefact est refusée. Les personnalisations
documentaires se font dans les sources du projet ou du module, jamais
dans le fichier compilé. Un second passage sans changement est idempotent.

Les docs sont en français par défaut. Toute traduction multilingue de
la documentation elle-même reste différée.

## Validation requise

CI Tooling, tests d'accès HTTP BO, contrôle des droits sur les scopes et
CT utilisateur. Le pipeline Setup n'exécute aucune étape documentaire.
