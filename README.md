# SymSvel Tooling

Bundle Symfony `symsvel-framework/tooling` : opérations de développement et de maintenance d'un workspace SymSvel. **La CLI autonome `symsvel` est l'entrée utilisateur privilégiée** ; les commandes `php bin/console symsvel:*` sont les points d'entrée Symfony et les outils de diagnostic.

> Référence de travail : branche `feat/60-update-init-simplification` (PR #62), coordonnée avec la CLI PR #10. Les parcours GitHub/Satis et les crash-tests XT ne sont pas encore validés de bout en bout. Ne pas confondre CI verte et validation terrain.

## Architecture et responsabilités

- **CLI** : création de projets, sélection du canal `stable` ou `dev`, opérations utilisateur sur les packages et composants.
- **Tooling** : installation, synchronisation des modules, migrations, transactions, mises à jour natives et récupération.
- **Core / Back** : exécution Symfony et socle applicatif léger ; la logique métier appartient aux modules.
- **BO / App / Front** : composants SvelteKit dont les fichiers installés appartiennent au projet.

Le canal de distribution est enregistré dans `backend/.symsvel/project.json` et **ne dépend pas de `APP_ENV`**. L'ancien environnement `core` n'est plus utilisé. Voir [le contrat de provenance de la CLI](https://github.com/Symsvel-Framework/symsvel-cli/blob/feat/persist-project-channel/docs/reference/provenance.md).

## Création d'un projet (parcours utilisateur)

```bash
symsvel doctor
symsvel create-project --components=all
# Projet de développement / crash-test :
symsvel create-project --components=all --dev
```

`--components=bo|app|front|all` sélectionne les interfaces, le BO étant inclus. Symfony réside dans `backend/`, les interfaces dans les dossiers frères `bo/`, `app/`, `front/`. La CLI pilote le Setup Wizard. Pour reprendre un setup interrompu : `symsvel setup`.

La commande Symfony `php bin/console symsvel:install --components=all` existe toujours dans le Tooling pour l'installation des interfaces ; **elle ne remplace pas** le parcours de création de projet de la CLI. Elle refuse une arborescence où Symfony n'est pas dans `backend/`, refuse les installations partielles ambiguës et ne doit pas écraser des composants préexistants.

### Initialisation documentaire

`WorkspaceInitializer` prépare `dev-doc/docs/`, `dev-doc/mkdocs.yml`, une page d'accueil minimale et `secrets/.env.example` sans écraser les fichiers existants. Il **n'installe pas** Python, pip, MkDocs ni d'environnement virtuel et **ne construit pas** le site documentaire. Le mécanisme de setup prépare aussi un jeton local dans `.symsvel/setup-token` : ne pas le versionner ni le divulguer. La génération documentaire est un chantier distinct.

## Maintenance : deux parcours différents

```bash
# Mise à jour sélective d'un package Composer :
symsvel update symsvel-framework/module-test-back
# Installation ou mise à jour native d'une interface :
symsvel update bo
```

`symsvel update bo|app|front` délègue à `symsvel:component:update`. Si le composant est absent, le Tooling utilise son installateur de cycle de vie ; sinon, `ComponentUpdater` compare la référence native, la nouvelle version officielle et les fichiers locaux. Il conserve les modifications locales non conflictuelles et refuse les collisions. Les sources officielles sont récupérées hors des dépôts Git applicatifs. **Il n'effectue pas de `git merge --ff-only`, de checkout ou de reset des interfaces du projet.**

`symsvel update <package>` passe par le lanceur transactionnel Composer. `symsvel update` sans cible et `php bin/console symsvel:update` ne doivent pas être assimilés à la mise à jour sélective d'une interface : le second point d'entrée utilise `WorkspaceUpdater` et possède son propre périmètre de snapshots.

Détails : [contrat des mises à jour natives](docs/component-updates-v1.md) et [transactions Composer](docs/composer-transactions.md).

## Modules Composer et Symfony Flex

Les modules `symsvel-framework/module-*` sont des packages Composer. Les recettes Symfony Flex déclarent les Bundles et imports de routes ; le Tooling prend en charge synchronisation Svelte, migrations, installation et retrait des ressources gérées. Le développement s'effectue dans le dépôt du module ou un workspace, jamais directement dans `vendor/`.

```bash
symsvel make:module documents --scopes=back,bo,app
symsvel require symsvel-framework/module-test-back
symsvel update symsvel-framework/module-test-back
symsvel remove symsvel-framework/module-test-back
```

Le ModuleMaker est implémenté dans Tooling et partagé avec la CLI. Il produit un modèle de recette Flex ; **la publication dans le catalogue de recettes est une étape distincte**. L'intégration Composer/Flex existe dans le code et les tests, mais son parcours réseau complet sur XT reste à valider. Les modules requis par le socle ne doivent pas être désinstallés.

Références : [contrat des modules](docs/modules/contrat.md), [commandes et migrations](docs/modules/commandes.md), [contrat de manifeste](docs/module-contract-v1.md).

## Transactions, rollback et nettoyage

Le lanceur Composer de Tooling est utilisé par `symsvel require/update/remove`. Il encadre les manifests, les sources et dépendances, les artefacts des interfaces, et la sauvegarde BDD **dans les conditions documentées**. PostgreSQL et SQLite sont les moteurs couverts par le checkpoint global ; l'absence de configuration BDD ou une restauration impossible limite les garanties. Composer natif n'est pas intercepté. Les effets externes ne sont pas annulés par les checkpoints locaux.

Après succès, les checkpoints éligibles sont nettoyés ou inscrits dans `backend/.symsvel/cleanup-pending.json` pour reprise :

```bash
php bin/console symsvel:cleanup
```

En cas de rollback incomplet, les checkpoints sont conservés pour diagnostic et **ne doivent pas être nettoyés automatiquement**. Voir [transactions et limites](docs/qualite/transactions.md).

## Qualité et validation

```bash
composer install
composer qa
```

Les tests automatisés et la CI ne remplacent pas les crash-tests XT sur Mac. Avant merge des PR CLI #10 et Tooling #62 : vérifier les parcours réels GitHub/Satis, le Setup Wizard, les modules, migrations, mises à jour, désinstallations et rollbacks. Toute modification de code doit actualiser sa documentation. Ne pas exécuter les opérations de maintenance sur un serveur de production.


### Chantier setup en deux phases (en cours)

L'installation initiale doit préparer les sources et modules sans déclencher de build Svelte. La première phase du Setup configure le projet, la base choisie parmi PostgreSQL, MySQL, MariaDB et SQLite, puis exécute les migrations. La deuxième phase crée directement le premier administrateur sans fichier temporaire de secrets. La finalisation lance les builds après les migrations, puis clôt le setup. Les tests doivent vérifier les reprises après erreur, les permissions du fichier `.env.local` et l'absence de build prématuré. Cette section décrit la cible et non l'état implémenté.

La machine d'état introduit `database_ready` entre `running` et `completed`, afin de matérialiser la fin des migrations avant la création de l'administrateur. Le parcours du Wizard et le découpage des commandes restent à adapter avant validation du chantier.
