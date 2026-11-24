# Intégration continue

Depuis le 22/11/2026, la CI active `.github/workflows/ci.yml` est exécutée sur **GitHub-hosted Ubuntu 24.04**, avec PHP 8.4 installé par `shivammathur/setup-php@v2`, Composer et les tests existants. Les `push` vers `dev` et `main`, ainsi que les PR vers `main`, déclenchent la CI. `ci.yml.stub` reste une archive inactive.

Les runners du mainteneur ne servent plus ces dépôts publics : ils restent réservés à `symsvel-packages` et `symsvel-recipes` (privés). CI et crash-tests restent des validations distinctes.

## Fixtures Git de tests

Les tests d'intégration de mise à jour native utilisent des dépôts Git temporaires avec une branche `dev`, conformément au canal de provenance `ProjectChannel::DEV` : le clone de `ComponentNativeSource` sélectionne explicitement `dev`. Les fixtures ne doivent pas créer uniquement `main`, faute de quoi les tests échouent avant d'atteindre leurs assertions métier. Les tests d'installation de composants déclarent explicitement le canal de leur backend temporaire avant d'exécuter le cycle de vie.

Dans les tests de cycle de vie, le fichier `.symsvel/project.json` est une donnée initiale de fixture. Les scénarios de refus vérifient qu'il reste intact et qu'aucun autre fichier n'apparaît dans `.symsvel`, plutôt que d'exiger l'absence du répertoire. Les dépôts locaux utilisés pour installer les composants sont initialisés sur `dev`.

## Couverture Doctrine et bases d'intégration

Les dépendances `doctrine/dbal` et `doctrine/orm` servent aux tests du compilateur Schema. La CI exécute PHPUnit avec `--display-skipped`.

Le workflow GitHub-hosted démarre des services **jetables et isolés par exécution** : PostgreSQL 17, MySQL 8.4 et MariaDB 11.8. Les bases de test se nomment `symsvel_rollback_ci` et utilisent un mot de passe fixe réservé à la CI (`symsvel_ci_only`), jamais une base projet. PostgreSQL écoute en local sur 5432, MySQL sur 3306 et MariaDB sur 3307. Les clients SQL sont installés dans le job, dont `pg_dump` 17. `mysqldump` désactive les statistiques de colonnes non disponibles dans MariaDB via un fichier de configuration temporaire.

Les tests de sauvegarde/restauration, destructifs par nature, n'utilisent que ces bases jetables et sont exécutés par le workflow. Les exécutions de branches différentes disposent chacune de leurs propres services, sans verrou global sur les runners du mainteneur. Les crash-tests locaux restent obligatoires avant une release.

## Releases sur GitHub-hosted (22/11/2026)

`.github/workflows/release.yml` publie les tags `vX.Y.Z` via GitHub-hosted Ubuntu 24.04 (PHP 8.4 / Composer installés dans le workflow). Les contrôles existants, la vérification du tag et la création de la GitHub Release restent inchangés. Une publication réelle doit encore être vérifiée.

## Contrôles des tests de refus I18n (19/11/2026)

Les tests de rejet du catalogue de sources de traduction et du
publisher vérifient désormais explicitement qu'une exception attendue
a bien été levée pour **chaque** entrée invalide. Auparavant, leurs
blocs `try/catch` pouvaient aboutir sans assertion enregistrée par
PHPUnit 13, entraînant trois tests `risky` malgré un comportement de
production correct. Le contrat I18n n'est pas modifié. Les validations
restent : CI verte, exécution PHPUnit sans ces risques, puis CT XT.

## Audit des dépendances

Après résolution et installation Composer, `composer audit --locked` doit réussir sans exception dans la CI et dans la validation des releases qui installent les dépendances. PHPUnit doit être au minimum en 13.4.4. Un audit vert ne remplace ni les tests ni le CT.

## Notices PHPUnit

La configuration PHPUnit commune affiche le détail des notices (`displayDetailsOnPhpunitNotices`) et rend leur présence bloquante (`failOnPhpunitNotice`). Ce contrôle s'applique aussi aux exécutions locales et à `composer qa`, sans masquer ni exclure de notice. La suite doit être propre avant validation du CT et publication.
