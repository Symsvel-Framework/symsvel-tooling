# Intégration continue

La branche `dev` est le terrain de développement ; `main` conserve la version stable.

Le workflow `.github/workflows/ci.yml` est déclenché par les `push` sur `dev` et `main`, ainsi que par les pull requests ciblant `main`. Les pull requests ciblant `dev` ne déclenchent pas de CI au titre de l'événement `pull_request` ; un `push` sur `dev` déclenche sa propre exécution. Les éventuels déclencheurs supplémentaires définis dans le workflow restent applicables.

Les jobs utilisent les runners auto-hébergés configurés dans le workflow. Une modification n'est validée qu'après exécution et réussite de la CI, puis les crash-tests locaux requis.

## Fixtures Git de tests

Les tests d'intégration de mise à jour native utilisent des dépôts Git temporaires avec une branche `dev`, conformément au canal de provenance `ProjectChannel::DEV` : le clone de `ComponentNativeSource` sélectionne explicitement `dev`. Les fixtures ne doivent pas créer uniquement `main`, faute de quoi les tests échouent avant d'atteindre leurs assertions métier. Les tests d'installation de composants déclarent explicitement le canal de leur backend temporaire avant d'exécuter le cycle de vie.

Dans les tests de cycle de vie, le fichier `.symsvel/project.json` est une donnée initiale de fixture. Les scénarios de refus vérifient qu'il reste intact et qu'aucun autre fichier n'apparaît dans `.symsvel`, plutôt que d'exiger l'absence du répertoire. Les dépôts locaux utilisés pour installer les composants sont initialisés sur `dev`.

## Couverture Doctrine et bases d'intégration

Les dépendances `doctrine/dbal` et `doctrine/orm` sont installées en `require-dev` uniquement pour les tests du moteur Schema. Elles restent fournies par le projet hôte à l'exécution du Tooling. La CI exécute PHPUnit avec `--display-skipped` pour exposer les éventuelles exclusions.

Le test destructif `WorkspaceDatabaseBackupTest::testMysqlAndMariaDbRestoreOriginalDataAndDropNewTables` exige `SYMSVEL_TEST_MYSQL_URL` et `SYMSVEL_TEST_MARIADB_URL` vers des bases dédiées nommées **exactement** `symsvel_rollback_ci`. Le Compose des runners fournit désormais PostgreSQL 17, MySQL 8.4 et MariaDB 11.4 sur le réseau Docker interne, sans publication de ports. L'image des runners inclut `mysql` et `mysqldump` (`default-mysql-client`). Le workflow fournit les deux URL de test à partir du secret `SYMSVEL_CI_POSTGRES_PASSWORD`, réutilisé comme mot de passe root **uniquement pour ces bases CI jetables**, et vérifie les connexions avant PHPUnit. Les sauvegardes et restaurations recréent `symsvel_rollback_ci` ; elles nécessitent les privilèges correspondants. Ne jamais diriger ces tests vers une base projet.

Les deux runners partagent ces bases. La concurrence du workflow Tooling utilise donc le groupe global `symsvel-tooling-shared-rollback-databases` avec `cancel-in-progress: false` pour empêcher les exécutions destructives simultanées, y compris entre `dev`, `main` et PR. Les autres dépôts restent parallélisables. Si un runner n’a pas été reconstruit avec le client MySQL ou si les conteneurs de bases ne sont pas sains, la CI échoue explicitement ; le test ne doit plus être silencieusement ignoré.

Le client `mysql` des assertions d'intégration est appelé avec `--batch --skip-column-names` afin de comparer les valeurs SQL sans l'en-tête de colonne. Cela ne modifie pas le format des archives produites par `mysqldump` ni la restauration.

## CD des releases sur runners locaux (16/11/2026)

Les tags `vX.Y.Z` conservent leur workflow `.github/workflows/release.yml` avec les mêmes validations et la création d'une GitHub Release, mais utilisent maintenant les runners `[self-hosted, linux, ARM64, symsvel]` au lieu des runners GitHub facturés. PHP 8.4 et Composer sont vérifiés directement sur l'image du runner, déjà utilisée par la CI ; l'action Ubuntu de configuration PHP a été supprimée. La publication de la release passe par `actions/github-script@v7` et l'API GitHub (vérification du tag et création de la release), sans dépendre d'un exécutable `gh` non garanti sur le conteneur.

Le workflow reste déclenché **uniquement par push d'un tag**, avec l'autorisation `contents: write` déjà nécessaire à la publication. Aucun tag ni release n'est créé par cette migration. Les runners doivent être en ligne ; les secrets et accès requis aux dépôts privés restent inchangés. Une release de validation et son journal sont nécessaires pour déclarer cette partie CD complètement validée. Le changement ne concerne pas la CI de branches ni le runtime SymSvel.
