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
Une installation locale de Python 3 est requise pour `create-project` :
Tooling crée un environnement isolé `dev-doc/.venv/`, installe MkDocs
depuis `dev-doc/requirements.txt` puis construit `dev-doc/site/`.
L'initialisation ne crée aucun site public pour `admin-doc` et `user-doc`.
Le build local produit immédiatement un `site/index.html` consultable.
Pour la prévisualisation HTTP (et les mises à jour automatiques), lancer :

```bash
cd dev-doc
.venv/bin/python -m mkdocs serve --dev-addr 127.0.0.1:8001
```

Cette adresse locale ne doit jamais être ouverte sur le VPS.
MkDocs reçoit automatiquement les **sources développeur réelles** :
`backend/docs/`, les `docs/` des hôtes BO/App/Front, celles de Core et
Tooling installés par Composer, puis les `docs/dev` des modules installés
et déclarés dans `module.json`. La commande de création de projet effectue
un second build après l'installation et la synchronisation des modules natifs.
Le répertoire `dev-doc/docs/symsvel/` est généré et réservé par Tooling ;
il ne doit pas être édité ou versionné. Les personnalisations sont rédigées
dans `dev-doc/docs/` en dehors de ce répertoire, ou dans les sources des
composants et modules. Le dossier généré est ignoré par Git.

## Rafraîchissement automatique en local

Après un `symsvel require`, `symsvel update` ou `symsvel remove`
réussi, le lanceur transactionnel de Tooling actualise automatiquement
`dev-doc/docs/symsvel/` puis relance MkDocs. Les pages d'un module supprimé
disparaissent de la publication, les pages nouvelles et modifiées sont
récupérées à partir des sources Composer réellement installées.
La mise à jour globale du workspace effectue la même opération **après**
sa transaction, jamais pendant la fenêtre de rollback.

Ces opérations sont réservées aux espaces de développement locaux qui
possèdent un `dev-doc/mkdocs.yml` valide ; elles sont ignorées si
`APP_ENV=prod`, en absence de `dev-doc`, ou pendant le bootstrap initial
(`SYMSVEL_DEFER_BUILDS=1`). Le site `dev-doc` n'est jamais déployé.
Un échec MkDocs est affiché en avertissement et se corrige avec
`symsvel documentation:build-dev`, sans faire passer une opération Composer
déjà validée pour un échec ni provoquer un rollback fictif.

Une édition directe de fichiers Markdown hors d'une opération CLI ne déclenche
pas une transaction : `mkdocs serve` peut surveiller les fichiers locaux
en développement, ou `symsvel documentation:build-dev` reconstruit
explicitement le site.

Le build local MkDocs est **distinct** de `symsvel documentation:sync` :
cette dernière commande maintient l'index privé pour l'interface BO et ne
publie jamais de site statique. Aucun guide `admin` ou `user` n'est copié
vers le site développeur.

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
CT utilisateur. Le Setup Wizard n'exécute aucune étape documentaire supplémentaire :
les builds sont réalisés à la création de projet, avant son ouverture.

## Séparation des guides et des notes mainteneur

Les composants contribuent uniquement leurs Markdown sous docs/guides/<rubrique>/. Les rubriques ordonnées sont 01-getting-started, 02-development, 03-modules, 04-updating, 05-deployment, 06-reference. Les fichiers historiques hors de ce périmètre restent consultables sur GitHub, sans être assemblés. Les modules utilisent le scope dev de leur manifeste ; un sous-dossier guides, lorsqu'il existe, devient leur source exclusive, les anciens modules personnalisés conservant leur comportement. Admin et user restent inchangés et privés. Le Publisher fusionne les guides des composants par rubrique, conserve les guides des modules sous 03-modules/<slug> et génère les index. Les collisions sont bloquantes. Les pages personnelles et la configuration MkDocs personnalisée sont préservées. Les composants anciens dépourvus de guides ne contribuent plus leurs historiques ; publier la refonte de Back et des modules de façon coordonnée avec Tooling.

Les captures locales sont placées dans un sous-répertoire images des guides dev. Le Publisher copie PNG, JPEG et WebP valides (2 Mio par fichier, limite totale 12 Mio), sans liens symboliques ni SVG/HTML. Les liens Markdown relatifs restent valides, par exemple ![Setup](images/local-config.png). Les images ne sont pas injectées dans le JSON privé. Les sources hors guides ne sont pas copiées. Aucun document de maintenance ni historique de CT ne doit être ajouté à docs/guides.
