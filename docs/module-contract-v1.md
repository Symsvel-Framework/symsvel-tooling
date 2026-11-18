# Contrat de manifeste des modules SymSvel (schema 1)

Le manifeste `module.json` utilise `schema: 1`. Les manifests historiques XCore ne sont pas compatibles. Le contrat est vérifié par `ModuleManifest::read()` dans Tooling. Un module est développé dans son dépôt ou dans `modules/<slug>/`, puis installé par Composer ; **le Maker ne développe jamais dans `vendor/`**.

## Champs et validations

- `schema` : entier `1` obligatoire.
- `name` : nom PascalCase, commençant par une majuscule, lettres et chiffres.
- `components` : objet associatif des scopes `back`, `bo`, `app`, `front`, avec `back: "back"` obligatoire. Chaque répertoire déclaré doit exister et ne pas être un lien symbolique.
- `documentation` : objet associatif des scopes `dev`, `admin`, `user`, avec `dev: "docs/dev"` obligatoire. Chaque répertoire déclaré doit exister et ne pas être un lien symbolique.
- `dependencies` : tableau ou objet JSON requis ; le traitement précis des dépendances relève des planificateurs de modules.
- `extensions` : tableau ou objet JSON requis.
- `tables` : liste optionnelle de noms de tables uniques, composés de lettres, chiffres et underscores, commençant par une lettre ou un underscore.

Exemple minimal conforme :

```json
{
  "schema": 1,
  "name": "Documents",
  "dependencies": {},
  "tables": [],
  "components": {"back": "back"},
  "documentation": {"dev": "docs/dev"},
  "extensions": []
}
```

Cet exemple exige les dossiers physiques `back/` et `docs/dev/` à côté de `module.json`.

## Génération et intégration

```bash
symsvel make:module documents --scopes=back,bo,app
```

Le Maker génère un bundle Symfony sous `back/src/`, des répertoires de migrations et tests, les scopes Svelte demandés, la documentation associée et un **modèle** de recette Flex. Les profils historiques `back`, `bo`, `app`, `front`, `all` sont acceptés. Le scope `back` est toujours ajouté ; les profils `app` et `front` ajoutent le BO, contrairement à une liste explicite de scopes où seuls les scopes demandés (plus `back`) sont ajoutés.

Le modèle `recipe/recipe.json` contient une référence de commit à remplacer et **n'est pas automatiquement publié** dans le catalogue Flex. L'installation, les routes, la synchronisation Svelte et les migrations sont assurées par les mécanismes Tooling/Composer/Flex appropriés, pas par la simple génération du dossier du module.

Voir [les commandes](modules/commandes.md), [l'intégration des modules](modules/contrat.md) et [les transactions Composer](composer-transactions.md). Les crash-tests XT restent nécessaires pour valider le parcours complet sur les dépôts officiels.

## Dictionnaires de traduction des modules (18/11/2026)

Chaque scope Svelte d'un module peut fournir `<scope>/src/lib/translations/<lang>.ts` (par exemple `bo/src/lib/translations/fr.ts`). Le Maker crée le dossier avec `.gitkeep` mais n'invente pas de libellés. Un module sans textes n'a pas besoin de fichier `.ts`.

Tooling synchronise les fichiers via le mécanisme existant `SvelteModuleSynchronizer`, dans l'hôte installé à `<scope>/src/lib/translations/<module>/<lang>.ts`. Le propriétaire `<module>` est l'identifiant Composer validé, jamais déduit d'une clé arbitraire. Les fichiers de langue des modules ne se remplacent pas mutuellement ; les conflits sur fichiers gérés, modifications locales et liens symboliques restent bloquants. Le retrait d'un module retire uniquement les sources qu'il avait synchronisées.

Convention publique : `<scope>.<module|system>.<key>`. Les dictionnaires source utilisent des clés relatives au module et au scope ; `system` est réservé aux textes natifs des hôtes, jamais aux textes fonctionnels des modules. Ce changement couvre les dossiers Maker et la copie des fichiers ; le raccordement BDD et le chargement i18n des sources font l'objet d'étapes distinctes, à tester avant toute validation d'installation complète.

### Contrat des fichiers source

Les fichiers par langue sont des exports TypeScript contenant exclusivement un objet JSON valide suivi de `as const;`. Exemple : `export default {"pagination":{"first":"Première page"}} as const;`. Aucun import, fonction, variable ou tableau exécutable. Tooling valide les sources, déplie les clés et publie atomiquement `backend/.symsvel/translation-sources.json` (schéma 1), avec scope, module|system, clé relative, langue et valeur. Cet inventaire local est préparé avant toute persistance, qui doit intervenir après migrations ; il ne constitue ni une API HTTP ni une autorité sur les personnalisations.

### Orchestration BDD

Après l'exécution des migrations Doctrine de Languages, Tooling invoque `symsvel:languages:translations:sync` lors du Setup initial (parcours en une ou deux phases) et des transactions Composer de modules. Le module vérifie l'inventaire `backend/.symsvel/translation-sources.json`, puis persiste ses clés et textes dans ses tables. L'opération est idempotente et protégée par les snapshots/transactions existants ; une erreur bloque le cycle plutôt que de signaler une installation fictive. La migration Languages `Version20261118000001` est enregistrée dans le registre global.

Les dictionnaires publiés après personnalisation depuis Languages sont écrits dans `src/lib/translations/generated/<lang>.ts`. Les anciens fichiers `src/lib/i18n/generated/` restent lisibles par les helpers Svelte pendant la transition, mais ne sont plus la cible des nouvelles publications. L'écrivain conserve les vérifications de signature, de répertoire géré, d'absence de symlink et de renommage atomique.

Les indices numériques sont acceptés en segment interne, par exemple `strengthLevels.0`, pour les listes de libellés fixes. Le dictionnaire source reste un objet JSON et non un tableau ; le consommateur peut reconstruire sa liste à partir de ces clés.

### Contrat testé sur plusieurs modules

Un test de synchronisation installe simultanément deux modules possédant chacun un fichier `bo/src/lib/translations/fr.ts`. Les fichiers de destination sont isolés par l'identifiant de module, la resynchronisation est idempotente, une mise à jour affecte seulement le module concerné, et son retrait supprime ses fichiers sans toucher aux traductions de l'autre module.

### Validation stricte des objets TypeScript

Le lecteur décode le JSON en objets (`stdClass`), pas en tableaux PHP, afin de distinguer sans ambiguïté `{"0":"Aucune saisie"}` d'une liste JSON. Les indices numériques restent des segments de clés légitimes. Les tableaux JSON, les expressions TypeScript, les feuilles non textuelles et les clés aplaties en doublon (`a.b` et `a → b`) sont refusés **avant** toute publication de l'inventaire ou copie Svelte. La suite de tests inclut ces régressions.

Les noms `system` et `generated` sont réservés dans les hôtes Svelte : `system` identifie exclusivement le composant natif, tandis que `generated` reçoit les personnalisations publiées depuis Languages. Un module Composer ne peut pas utiliser ces identifiants comme propriétaire de fichiers `lib/translations` ; Tooling refuse le conflit pendant le préflight et le synchroniseur protège également l'appel direct.

### Validation du parseur PHP (18/11/2026)

La CI de la branche a détecté une erreur d'échappement dans l'exception levée pour un dictionnaire Svelte invalide. Correction du namespace PHP dans `SvelteModuleSynchronizer` ; le lint `php -l` de tous les fichiers reste un prérequis avant toute validation du chantier.

### Mise à jour globale des hôtes (`symsvel update`)

Le cycle existant `WorkspaceUpdater` applique les sources natives BO/App/Front, puis lance `symsvel:module:sync --source=composer` pour reconstruire l'inventaire des modules et des dictionnaires. Si la BDD locale est configurée et Languages installé, il lance ensuite `symsvel:languages:translations:sync`, avant `npm ci` et les builds Svelte. Les snapshots sources/runtime/BDD du même cycle couvrent ces étapes : un échec annule la mise à jour plutôt que de laisser un inventaire incohérent. Les tests vérifient l'ordre de ces commandes ; les CT XT devront couvrir les échecs et restaurations sur projet réel.

### Contrôle de CI sur branche de travail

La CI a été exécutée sur la branche de traduction avec les runners locaux ; le déclencheur temporaire de cette branche a ensuite été retiré, et les règles de publication/restauration du workflow (`dev`, `main` et PR selon le dépôt) ont été conservées. Les CT XT restent requis avant merge.

### Documentation générée par `make:module`

Le Maker renseigne désormais `docs/dev/index.md` du nouveau module avec le chemin des dictionnaires de chaque scope, la convention publique `<scope>.<module>.<key>`, l'exigence d'un export JSON strict `as const` et la synchronisation post-migrations. Le squelette reste volontairement dépourvu de libellés fictifs ; un test vérifie que ces indications sont présentes dans la documentation générée.
