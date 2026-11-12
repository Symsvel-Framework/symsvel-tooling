# Transactions Composer SymSvel (expérimental)

Le lanceur transactionnel est **optionnel** : les commandes natives `composer require`, `composer update` et `composer remove` ne sont **pas interceptées**. Le script est publié dans `backend/scripts/` lors de l'installation des hooks Composer par le Tooling, notamment via le parcours `symsvel:update`. La CLI peut également utiliser le lanceur pour ses opérations transactionnelles.

Depuis `backend/` :

```bash
php scripts/symsvel-composer-transaction.php require symsvel-framework/module-test-back:dev-main
php scripts/symsvel-composer-transaction.php update symsvel-framework/module-test-back
php scripts/symsvel-composer-transaction.php remove symsvel-framework/module-test-back
```

## Périmètre et prérequis

Le lanceur exige un `composer.lock` initial et crée un checkpoint dans `backend/.symsvel/transactions/<id>/`. Il sauvegarde :

- les manifests `composer.json`, `composer.lock` et `symfony.lock` s'il existe ;
- les fichiers sources du workspace BACK/BO/APP/FRONT, y compris les inventaires `.symsvel` (hors checkpoints transactionnels, registre de nettoyage, dépendances, builds et Git) ;
- `backend/vendor/` et les artefacts `node_modules/`, `build/`, `dist/`, `.svelte-kit/` des interfaces présentes.

La commande Composer est exécutée avec les plugins Flex et les scripts Composer actifs, afin d'inclure les recettes et la synchronisation SymSvel dans la transaction. Les options `--no-scripts` et `--no-plugins` sont refusées par le lanceur. Les options modifiant le répertoire de travail sont également refusées.

## Échec et restauration

Si Composer échoue, le lanceur tente de restaurer les manifests, `vendor/`, les sources et les artefacts des interfaces. Il compare ensuite les empreintes des fichiers sources à leur état initial. Si une restauration ou une vérification échoue, la commande signale une **récupération incomplète** et conserve le checkpoint. Elle ne prétend pas avoir restauré ce qu'elle n'a pas pu vérifier.

**Limites importantes :** le lanceur Composer peut créer un checkpoint global de la base lorsque `DATABASE_URL` est explicitement disponible ; cette sauvegarde prend en charge PostgreSQL et SQLite. En l'absence de configuration BDD, aucune restauration globale de la base ne peut être garantie. Il ne restaure pas les références Git et ne compense pas les effets sur des services externes. Il ne remplace donc pas le mécanisme global de `symsvel:update`. En particulier, un script tiers ou une action manuelle hors de son périmètre ne bénéficie pas d'une transaction complète.

Après succès, le lanceur inscrit son checkpoint dans `backend/.symsvel/cleanup-pending.json` **avant** de lancer son nettoyage. Comme pour `symsvel:update`, une suppression interrompue est reprise par `php bin/console symsvel:cleanup` (éventuellement via cron). Si l'enregistrement échoue, l'opération Composer reste réussie, le checkpoint est conservé et un avertissement est émis via le suivi de progression. Les checkpoints d'une récupération incomplète ne sont jamais inscrits au registre.

## Mise à jour globale du workspace

`php bin/console symsvel:update` (en environnement Symfony `dev`) utilise un mécanisme distinct : checkpoints Composer, sources natives, dépendances, builds et **base de données**. Le parcours actif des composants ne fusionne plus les dépôts Git du projet. PostgreSQL et SQLite sont pris en charge ; une `DATABASE_URL` valide est obligatoire. Les échecs de restauration sont signalés et leurs checkpoints conservés.

Après une **mise à jour globale réussie**, les checkpoints sont inscrits dans `backend/.symsvel/cleanup-pending.json`. Le nettoyage peut être repris par :

```bash
php bin/console symsvel:cleanup
```

Cette commande est conçue pour être exécutée périodiquement par cron, mais aucun cron n'est installé automatiquement. Elle ne doit jamais supprimer les checkpoints d'un rollback incomplet.

Voir également [le README](../README.md).

## Provenance des nouveaux modules en canal dev

Pour un projet dont `backend/.symsvel/project.json` déclare `channel: dev`, la transaction `require symsvel-framework/module-*` prépare désormais le dépôt Git officiel `https://github.com/Symsvel-Framework/symsvel-module-*.git` **après la prise du checkpoint Composer et avant le require**. L'entrée `repositories.module-<nom>-dev` est vérifiée lorsqu'elle existe déjà : une définition incompatible provoque un échec sans écrasement. Les dépendances tierces et les opérations `update`/`remove` ne déclenchent aucune déclaration de dépôt.

En cas d'échec, `composer.json` est restauré par le mécanisme transactionnel existant ; un test vérifie ce scénario par injection d'une erreur Composer. Le bootstrap initial est désormais délégué au même lanceur transactionnel par la CLI en cours de refonte (PR #10) ; sa déclaration VCS n'est plus effectuée avant le checkpoint. La résolution réelle via GitHub/Satis et les crash-tests XT restent à valider.

### Fixtures de bootstrap (07/11/2026)

Les tests de bootstrap des modules créent explicitement un canal `stable` lorsque la transaction atteint la résolution des sources. Cela évite de confondre une fixture sans provenance avec un défaut de la logique métier. Le nettoyage des fixtures retire également `backend/.symsvel/project.json`. La CI de la PR #62 était verte au commit `998642fb` le 07/11/2026, avant cette actualisation documentaire.

### Intégration VCS Git locale (07/11/2026)

Un test d'intégration supplémentaire initialise un vrai dépôt Git local avec un package `symsvel-framework/module-demo` sur `main`. Le lanceur transactionnel déclare le dépôt officiel puis l'injection de commande substitue uniquement l'URL par la fixture locale pour éviter le réseau. Composer effectue un vrai `require` en `dev-main`, suivi d'un échec injecté ; le test vérifie la restauration byte-à-byte de `composer.json` et `composer.lock` et l'absence du module dans `vendor`. Ce test ne prouve pas encore l'accès au dépôt GitHub réel. La CI de la PR #62 était verte au commit `998642fb` le 07/11/2026 ; les crash-tests XT restent à effectuer.

### Dépôts VCS déjà déclarés

Deux tests unitaires supplémentaires contrôlent la réutilisation sans mutation d'un dépôt officiel identique et le rejet d'un dépôt de même clé pointant vers une autre URL, avant tout `composer require`. Le rejet préserve le manifeste original. Ces scénarios sont couverts par la CI verte de la PR #62 au commit `998642fb` ; la vérification réseau réelle reste distincte.

### Matrice finale avant crash-test XT

- `dev` : intégration Composer réelle avec dépôt Git local sur `main`, installation réussie de `module-demo:dev-main` et conservation du canal ; un second scénario injecte un échec après l'installation et vérifie le rollback.
- `stable` : test de non-déclaration VCS développement lors d'un `require` avec contrainte explicite.
- Dépendances tierces : aucun dépôt VCS SymSvel ajouté en canal `dev`.
- Dépôts existants : réutilisation de la définition officielle, refus des conflits sans écrasement.

Les tests locaux Git substituent l'URL officielle par un dépôt temporaire uniquement dans l'exécuteur de test. Ils ne valident ni l'accès réseau GitHub, ni Satis, ni le parcours complet CLI/Setup Wizard. Ces points constituent les crash-tests XT à exécuter après confirmation de la CI.

### Fixture Git de réussite

La fixture de résolution Composer réussie inclut un manifeste `module.json` conforme au contrat SymSvel v1 et les répertoires `back/` et `docs/dev/`. Un simple `composer.json` suffit à la résolution Composer mais pas à la synchronisation des modules SymSvel après installation. La CI de la PR #62 était verte au commit `998642fb` ; le test sur XT reste nécessaire.

## Modules locaux en développement

En canal `dev`, `symsvel require symsvel-framework/module-<slug>` privilégie le package présent dans `modules/<slug>/composer.json` lorsqu'il existe et que son champ `name` correspond exactement à la demande. Tooling configure alors, **dans la transaction Composer existante**, un repository `path` relatif à `backend/`, avec lien symbolique et version `dev-main` explicite pour les modules sans tag Git. Le module reste éditable directement dans le workspace. Si le répertoire local n'existe pas, la résolution VCS officielle précédente reste inchangée.

Un dossier local avec un `composer.json` absent, symbolique ou dont le nom diffère provoque un refus explicite, sans basculement silencieux vers Satis. Un repository déjà déclaré sous la même clé doit être identique ; aucun écrasement implicite n'est autorisé. Le checkpoint transactionnel protège les modifications du manifeste Composer et les restaure en cas d'échec. Ce raccordement ne modifie pas à lui seul la découverte ORM de `symsvel module:migrate`, qui reste à crash-tester après installation.

Tests unitaires : priorité du `path`, validation du nom, comportement VCS distant et conflit de repository. Validation finale requise : CI verte et crash-test dans XT.

## Correctif du nettoyage des migrations compilées (12/11/2026)

Lors de `symsvel remove symsvel-framework/module-<slug>`, le lanceur utilise le registre du workspace `.symsvel/migrations.json` pour retrouver les versions Doctrine du module, exécuter les migrations descendantes avant le retrait Composer, puis supprimer les fichiers correspondants de `backend/migrations/`. La table `doctrine_migration_versions` conserve le suivi d'exécution propre à Doctrine.

Un défaut d'échappement dans l'expression régulière de `ComposerTransactionRunner::removeCompiledMigrations()` rejetait les identifiants valides de forme `DoctrineMigrations\\Version20261112092244`, provoquant l'annulation transactionnelle après l'étape Composer. L'expression a été alignée sur celle de `ModuleMigrationRemovalExecutor` et un test de non-régression vérifie la suppression effective du fichier compilé.

**Validation restante :** CI du dépôt et crash-test complet `require → migrations → remove` sur XT ; contrôler ensemble les tables SQL, les versions Doctrine, le registre SymSvel et les fichiers compilés. Un message de restauration ne dispense pas de vérifier l'état final en cas d'échec.


## Builds Svelte sélectifs après synchronisation Composer (12/11/2026)

Le hook `assets/symsvel-module-sync.php` exécute toujours `symsvel:module:sync --source=composer`. Il compare ensuite les inventaires du registre existant `backend/.symsvel/svelte-files.json` avant et après la synchronisation, en utilisant les empreintes SHA-256 des fichiers gérés. Une création, modification ou suppression sous `bo/src/`, `app/src/` ou `front/src/` déclenche `npm run build` **uniquement pour le composant concerné**. Un composant sans modification gérée affiche `unchanged, skipping build`. Les vérifications de présence du composant et de `package.json`, ainsi que l'échec bloquant des builds réellement lancés, restent inchangés.

Cette optimisation porte exclusivement sur les builds déclenchés par la synchronisation des **fichiers de modules Svelte gérés**. Elle ne remplace pas les builds explicites du setup, d'une mise à jour des dépendances npm ou d'une modification manuelle du code Svelte hors registre. Le registre absent avant une première synchronisation est traité comme vide ; les fichiers nouvellement publiés déclenchent leur build. Les tests unitaires couvrent les créations, modifications, suppressions, composants inchangés et frontières de chemins. CI et crash-test XT requis avant validation.


## Canal dev et branches Git (12/11/2026)

Le lanceur transactionnel, lorsqu'il reçoit `--dev` sur un `require` sans contrainte explicite, demande désormais `dev-dev` (branche Git `dev`) plutôt que `dev-main`. La CLI utilise également `dev-dev` lors du bootstrap des modules natifs. Les dépôts VCS officiels sont préparés dans la transaction existante ; les contraintes explicites restent prioritaires. Les références historiques à `dev-main` ci-dessus documentent les anciennes fixtures et ne décrivent pas le nouveau canal. Vérification finale : CI puis crash-test XT avec contrôle des versions résolues dans `composer.lock`.
