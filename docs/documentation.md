# Documentation SymSvel : assemblage hors Setup

Le contenu documentaire est fourni par chaque composant et module sous
`docs/`. Pour les modules Composer, seules les sources explicitement
déclarées dans `module.json.documentation` (`dev`, `admin`, `user`)
sont intégrées. Les sources des hôtes Back, BO, App et Front présentes
dans le workspace contribuent au scope `dev`. Les documents rédigés
spécifiquement pour le projet sont stockés sous `admin-doc/docs/` et
`user-doc/docs/`. Ils sont assemblés respectivement dans les scopes
privés `admin` et `user` par la même commande.

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

## Espaces documentaires et déploiement

Le bootstrap du workspace crée `dev-doc/`, `admin-doc/` et
`user-doc/` avec un répertoire `docs/` et un `.gitignore` chacun.
Aucun environnement Python, build MkDocs ou site exposé n'est créé.

- `dev-doc/` appartient au **développement uniquement**. Il ne doit
  jamais être synchronisé vers le VPS de production, ni être inclus dans
  ses artefacts.
- `admin-doc/docs/` contient les guides rédigés pour les administrateurs ;
  seul le BO authentifié peut les exposer.
- `user-doc/docs/` contient les guides destinés aux utilisateurs ;
  leur publication est contrôlée par les droits applicatifs.
- L'index assemblé `backend/var/symsvel/documentation.json` reste un
  **artefact privé**. Ne jamais le placer dans un répertoire servi statiquement.
  Les fichiers documentaires n'ont pas de vhost ou route Nginx publique
  dédiée ; BO et App restent les interfaces de lecture.
- Le packaging de production est une **liste positive** : runtime PHP,
  builds Svelte prévalidés et documentation admin/user strictement
  nécessaire. Il exclut `dev-doc`, sources Svelte, outillage de développement
  et registres de maintenance locaux.

Les répertoires documentaires à la racine du workspace ne sont pas
automatiquement couverts par les dépôts Git distincts `backend/`,
`bo/`, `app/`, `front/` et `dev-doc/`. Le projet doit définir leur
stratégie de versionnement avant d'y stocker des contenus métier.

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
