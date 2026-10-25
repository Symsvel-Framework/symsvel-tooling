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

Le service `SymSvel\\Tooling\\Installation\\ComponentInstaller` centralise la sélection et l'installation. Il vérifie **toutes** les destinations avant le premier clonage, refuse les dossiers existants et annule les nouveaux dossiers créés si un clonage, une installation npm ou un build échoue. Les chemins préexistants ne sont jamais supprimés.

## Prochaine étape

Réutiliser les mécanismes communs pour `symsvel:update`, avec des politiques de version distinctes : `APP_ENV=CORE` pour les derniers commits, `dev` pour les releases et `prod` sans mise à jour. Les mises à jour restent exclusivement locales. **Cette commande de mise à jour n'est pas encore implémentée.**

## Tests

```bash
composer install
composer qa
```

Toute modification de comportement doit être accompagnée de tests et d'une mise à jour de cette documentation.
