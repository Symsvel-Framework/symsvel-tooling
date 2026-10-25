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

Le BO est toujours installé. Les dépôts privés sont clonés en SSH dans `bo/`, `app/` et `front/`. Configurer l'accès GitHub SSH avant l'exécution, puis lancer `npm ci` dans chaque interface installée.

Le service `SymSvel\\Tooling\\Installation\\ComponentInstaller` centralise la sélection et l'installation. Il vérifie **toutes** les destinations avant le premier clonage, refuse les dossiers existants et annule les nouveaux dossiers créés si un clonage échoue. Les chemins préexistants ne sont jamais supprimés.

## Prochaine étape

Réutiliser les mécanismes communs pour `symsvel:update`, avec des politiques de version distinctes : `APP_ENV=CORE` pour les derniers commits, `dev` pour les releases et `prod` sans mise à jour. Les mises à jour restent exclusivement locales. **Cette commande de mise à jour n'est pas encore implémentée.**

## Tests

```bash
composer install
composer qa
```

Toute modification de comportement doit être accompagnée de tests et d'une mise à jour de cette documentation.
