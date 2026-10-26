# SymSvel Tooling

Outils CLI de développement du framework SymSvel (bundle Symfony, package Composer `symsvel-framework/tooling`).

## Architecture

- **Core** : services d'exécution, indépendants du Tooling.
- **Tooling** : commandes locales, installation et futures mises à jour.
- **Back** : projet Symfony qui consomme Core et Tooling.

Le Tooling dépend de Core, jamais l'inverse. Les commandes d'installation et de mise à jour ne doivent pas être exécutées sur le serveur de production.

## Installation des interfaces

```bash
php bin/console symsvel:install
```

Choix interactifs :

1. BO
2. BO + App
3. BO + Front
4. BO + App + Front

Pour les installations reproductibles et la CI, utilisez sans interaction :

```bash
php bin/console symsvel:install --no-interaction --components=all
```

Valeurs acceptées : `bo` (BO seul), `app` (BO + App), `front` (BO + Front), `all` (les trois). Sans option, le choix interactif reste disponible. Une valeur inconnue est refusée sans toucher aux dossiers.

Le BO est toujours installé. Symfony doit résider dans `<projet>/backend/`. Les dépôts privés sont clonés en SSH dans les dossiers frères `<projet>/bo/`, `<projet>/app/` et `<projet>/front/`. L'ancien schéma plat est refusé pour éviter de mélanger les composants. Configurer l'accès GitHub SSH et installer Node.js/npm avant l'exécution. Pour chaque interface, l'installateur exécute automatiquement `npm ci` puis `npm run build`. Les interfaces peuvent ensuite être lancées localement avec `npm run dev`.

Le service `SymSvel\\Tooling\\Installation\\ComponentInstaller` centralise la sélection et l'installation. Il vérifie **toutes** les destinations avant le premier clonage. Si toutes les interfaces demandées existent déjà, il ne les touche pas et initialise uniquement les dossiers communs ; une installation partielle est refusée. Il annule les nouveaux dossiers créés si un clonage, une installation npm ou un build échoue. Les chemins préexistants ne sont jamais supprimés.

## Documentation développeur et secrets

L'installation initialise également `<projet>/dev-doc/` avec MkDocs dans un environnement Python isolé (`.venv`), génère `dev-doc/site/` et crée `<projet>/secrets/.env.example`. Les dossiers existants ne sont pas écrasés. Python 3 avec `venv` et `pip` est nécessaire en local. `site/` et `.venv/` sont ignorés par Git ; le dossier `secrets/` reste hors versionnement.

## Prochaine étape

Réutiliser les mécanismes communs pour `symsvel:update`, avec des politiques de version distinctes : `APP_ENV=core` pour les derniers commits, `dev` pour les releases et `prod` sans mise à jour. Les mises à jour restent exclusivement locales. **Cette commande de mise à jour n'est pas encore implémentée.**

## Tests

```bash
composer install
composer qa
```

Toute modification de comportement doit être accompagnée de tests et d'une mise à jour de cette documentation.

## Mise à jour locale (première étape)

```bash
APP_ENV=core php bin/console symsvel:update
```

En mode `core`, actualise Core et Tooling via Composer, puis met à jour par avance rapide (`git merge --ff-only origin/main`) les interfaces présentes et relance `npm ci` et `npm run build`. Les modifications Git non commitées empêchent la mise à jour. Les composants absents sont ignorés. Le mode `prod` est interdit. En mode `dev`, Composer doit être configuré avec des contraintes de versions stables pour Core et Tooling (et non `dev-main`) ; les interfaces présentes sont positionnées sur leur dernier tag stable `vX.Y.Z` puis reconstruites. Sans tag stable, la mise à jour échoue explicitement. Les interfaces Git sont placées en HEAD détachée sur le tag sélectionné. Commande à exécuter exclusivement en local.

## Lanceur autonome du workspace

Le lanceur versionné est `bin/symsvel` (exécutable). Depuis la racine du workspace SymSvel, créer une fois le lien :

```bash
ln -s framework/tools/bin/symsvel symsvel
```

Puis créer un module directement dans `SymSvel/modules/` :

```bash
./symsvel make:module documents
```

Le lanceur partage `ModuleMaker` avec la commande Symfony `symsvel:make:module` et fonctionne sans backend Symfony ni XT.

## Maker par profils

Le socle obligatoire est `back` + `docs/dev`. Sans option, seul ce socle est généré.

| Profil | Composants | Documentation |
| --- | --- | --- |
| `bo` | back, bo | dev, admin |
| `app` | back, bo, app | dev, admin, user |
| `front` | back, bo, front | dev, admin, user |
| `all` | back, bo, app, front | dev, admin, user |

```bash
./symsvel make:module documents
./symsvel make:module documents --scopes=app
php bin/console symsvel:make:module documents --scopes=all
```

Les anciens scopes séparés ou combinés ne sont plus acceptés. Le maker génère les points d'extension des composants, la configuration PHPUnit et le workflow QA.
