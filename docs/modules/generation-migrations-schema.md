# Génération des migrations Schema par module

## Objectif validé (11 novembre 2026)

La commande publique cible est `symsvel module:migrate [MonModule]`.
Elle génère une **nouvelle migration source PHP** dans le dossier `Migration/` du module, au format `VersionYYYYMMDDHHMMSS.php`, avec les corps `up(Schema $schema)` et `down(Schema $schema)`.
La compilation, l'exécution et le registre existants restent la responsabilité du Tooling. La génération ne doit pas modifier une migration source existante ni écrire directement dans `backend/migrations/`.

## Résolution du module : première étape implémentée

`ModuleWorkingDirectoryResolver` s'appuie sur `ModuleRegistry` et `ModuleManifest`, déjà présents. Un argument explicite peut être le slug ou le nom PascalCase du manifeste. Sans argument, le répertoire courant doit se trouver dans le dossier du module, y compris un sous-répertoire. Un contexte ambigu ou extérieur à un module est refusé. Le chemin canonique est vérifié par `realpath` et la comparaison respecte les frontières de répertoire.

**État :** le résolveur, un sélecteur de métadonnées ORM par emplacement physique des classes et leurs premiers tests sont ajoutés. Un premier constructeur `ModuleDesiredSchema` s'appuie sur `SchemaTool::getSchemaFromMetadata()` avec les seules métadonnées sélectionnées ; il retourne le schéma cible et les noms de tables produits. Doctrine ORM est fourni par le projet hôte, sans ajout de dépendance runtime au Tooling. **Ce constructeur n'est pas encore raccordé à une commande** et son comportement sur les relations intermodules reste à valider. Le diff, le renderer PHP et la génération du fichier ne sont **pas encore implémentés**. Aucun crash-test n'est revendiqué.

## Contrat de sécurité et périmètre à réaliser

- Une commande = un seul module ; ne jamais générer de modification d'une table appartenant à un autre module.
- Filtrer les métadonnées ORM selon le module ; conserver les informations nécessaires aux clés étrangères intermodules.
- Ne créer aucun fichier si le schéma n'a pas changé.
- Comparer les options de référence (base réelle, snapshot, historique) avant de choisir ; le registre des migrations exécutées ne constitue pas à lui seul un snapshot de schéma.
- Signaler les opérations destructrices ou ambiguës, notamment les renommages ; ne pas prétendre restaurer les données avec un simple `down()`.
- Les migrations DML sont hors du premier jalon.
- Tests automatisés, CI verte, documentation actualisée et crash-tests utilisateur sont nécessaires avant validation du chantier.

## Test de développement prévu

Après intégration de la commande : `./pull.sh`, création d'un module de laboratoire avec `symsvel make:module`, ajout d'une entité Doctrine simple, lancement de `symsvel module:migrate` depuis le module, puis modification de l'entité et seconde génération. Les crash-tests d'exécution multi-SGBD seront réalisés dans une étape ultérieure.

## Protection du périmètre de comparaison (jalon en cours)

`ModuleSchemaScope` prépare deux schémas comparables, sans comparer l'intégralité de la base au sous-ensemble ORM du module. Les tables cibles doivent être déclarées dans `module.json` (`tables`), sinon la génération est refusée. Les tables de la base qui ne figurent pas dans le schéma ORM cible sont écartées **des deux côtés de la comparaison**, même si elles sont déclarées : une entité manquante ne doit pas déclencher une suppression implicite. Les schémas d'entrée restent intacts. Les tests vérifient l'isolement et les refus.

**Limites :** ce composant prépare seulement le périmètre. Il n'effectue pas encore le diff DBAL ni la génération PHP. La propriété exclusive des tables entre tous les manifests et les clés étrangères intermodules doivent encore être contrôlées avant l'écriture. Les suppressions de tables ne sont volontairement pas déduites de la disparition d'une entité.

## Exclusivité des tables déclarées

`ModuleTableOwnershipGuard::assertExclusive()` vérifie les déclarations `tables` de **tous** les modules découverts, rejette les collisions entre modules et exige que chaque table ORM cible appartienne exclusivement au module sélectionné. Ce contrôle doit être appelé avant toute écriture de migration ; il n'est pas encore raccordé à une commande. Des tests unitaires couvrent les collisions, les tables non déclarées et le cas valide. Il ne vérifie pas encore les contraintes de clés étrangères intermodules et ne prouve pas qu'une table existante en base appartient au module sans déclaration contradictoire extérieure au workspace.

## Vérification des clés étrangères sortantes

`ModuleForeignKeyGuard` refuse les clés étrangères vers des tables absentes des déclarations `tables` des modules découverts. Les tables sources doivent appartenir au module sélectionné ; les références à une table déclarée par un autre module sont autorisées sans en transférer la propriété. Les déclarations en conflit sont refusées. Tests unitaires : référence intermodule autorisée, cible inconnue, source étrangère.

**Non raccordé à la commande** : ce contrôle ne garantit ni la présence physique de la table cible ni l'absence de clés étrangères entrantes depuis d'autres modules. Ces vérifications restent nécessaires avant l'écriture d'une migration ; aucune exécution n'est validée à ce stade.

## Comparaison DBAL ciblée (jalon en cours)

`ModuleSchemaComparator::compare()` introspecte le schéma réel via le SchemaManager de la connexion, vérifie l'exclusivité des tables déclarées et les clés étrangères sortantes, restreint les deux schémas aux tables ORM du module, puis appelle `createComparator()->compareSchemas()`. Le résultat contient le `SchemaDiff` DBAL et la liste des tables ciblées. Aucun SQL n'est produit, aucune migration n'est écrite ni exécutée. Les tests unitaires avec doubles vérifient l'isolation et le refus d'un conflit avant la comparaison.

**Important :** ce composant ne valide pas encore les opérations destructrices du diff, les contraintes entrantes, ni l'existence des tables référencées. Il n'est pas encore branché à la commande. Une intégration avec un vrai DBAL et les crash-tests PostgreSQL/MySQL restent nécessaires avant d'utiliser le diff pour générer du PHP Schema.

## Protection des dépendances intermodules lors du diff

Avant toute comparaison DBAL, `ModuleSchemaComparator` refuse désormais les clés étrangères **entrantes** depuis une table extérieure vers une table ORM du module. Il vérifie aussi qu'une cible de clé étrangère **sortante** située hors du module existe physiquement dans le schéma introspecté, même si elle est déclarée dans un manifeste. Ces refus sont volontaires : retirer les tables extérieures du schéma comparé pourrait masquer une dépendance. Les tests vérifient l'arrêt avant `compareSchemas()`. Aucune suppression automatique de contrainte externe n'est autorisée.

Limite : le diff n'est toujours pas analysé opération par opération pour empêcher les suppressions de colonnes ou de tables. Ce contrôle et les essais sur vrais moteurs DBAL restent nécessaires avant le raccordement à la génération de migrations.

### Correctif de syntaxe

Correction des noms pleinement qualifiés `\\DomainException` accidentellement écrits avec deux antislashs dans le comparateur et ses tests. Le lint PHP doit passer avant toute validation fonctionnelle.

## Garde-fou sur les opérations destructrices

`ModuleSchemaDiffGuard` est exécuté après la comparaison DBAL et avant la remise du résultat au générateur. Il rejette les suppressions de tables et, par précaution, **toute modification d'une table existante** : un changement peut cacher une suppression de colonne, un renommage ou une conversion avec perte de données. La création de tables neuves reste permise. Les tests couvrent les trois cas et les objets de diff incompatibles. Ce choix est provisoirement restrictif et devra être affiné pour les ajouts de colonnes sûrs. La commande et le rendu PHP Schema ne sont toujours pas raccordés.

## Alignement sur l'API Doctrine DBAL 4

Le diff utilise `SchemaDiff::getAlteredTables()` et non `getChangedTables()` (nom inexistant dans DBAL 4). Le garde-fou vérifie également les suppressions de schémas et de séquences ainsi que les modifications de séquences, pour éviter de valider un diff destructeur non limité aux tables. Les doubles des tests reproduisent ces accesseurs DBAL 4. Cette vérification statique ne remplace pas un test d'intégration sur une véritable connexion Doctrine.

## Diff vide et création de fichier

Le comparateur renvoie désormais `changed: bool` en plus du diff et des tables ciblées. Le garde-fou utilise `getCreatedTables()` de DBAL pour distinguer une création réelle d'un schéma inchangé, après avoir refusé les suppressions et modifications de tables. La future commande devra **ne créer aucun fichier** si `changed` vaut `false`. Des tests vérifient le cas sans changement et la création d'une nouvelle table. Aucun fichier de migration n'est encore généré par cette étape.

## Couverture des objets hors tables

Le garde-fou refuse aussi la **création** de schémas et de séquences, en complément de leur suppression ou modification. Le booléen `changed` ne doit jamais considérer comme « sans changement » un diff contenant une opération que le renderer ne sait pas représenter. Les tests couvrent les créations de séquence et de schéma, notamment en présence d'une nouvelle table. Ces refus restent conservateurs jusqu'à la prise en charge explicite de ces opérations.

## Écriture immuable d'une migration source

`ModuleMigrationSourceWriter` crée un fichier `Migration/VersionYYYYMMDDHHMMSS.php` dans le module résolu, sans toucher aux migrations compilées. L'ouverture exclusive (`fopen(..., 'x')`) interdit tout écrasement en cas de collision de timestamp ; le dossier `Migration` symbolique est refusé. Une écriture partielle entraîne la suppression du nouveau fichier. Les tests vérifient la création, la collision et le refus des liens symboliques. **Ce composant ne fabrique pas encore le contenu PHP Schema** : il sera appelé uniquement après un diff autorisé et un rendu valide ; la commande n'est pas encore raccordée.

## Correctif du nettoyage des tests (CI)

Le test de refus d'un répertoire `Migration` symbolique pointe volontairement vers `/tmp`. Son `tearDown()` ne doit **jamais parcourir la cible du lien** : il supprime d'abord le lien lui-même et ne parcourt que les fichiers `Version*.php` du dossier de test lorsqu'il s'agit d'un véritable répertoire. Cela élimine les avertissements `unlink(...): Is a directory` sur les runners et protège leurs répertoires temporaires. Aucun changement au writer de production.

## Renderer PHP Schema (premier périmètre)

`ModuleSchemaMigrationRenderer::render()` produit une classe `VersionYYYYMMDDHHMMSS` avec `up(Schema $schema)` et `down(Schema $schema)` pour les **créations de tables simples** seulement. Les colonnes et clés primaires sont représentées avec l'API Doctrine Schema ; le `down()` supprime les tables nouvellement créées en ordre inverse. Le renderer refuse les clés étrangères. Les index secondaires simples (uniques ou non) sont désormais rendus via `addUniqueIndex()` et `addIndex()`, en conservant leurs noms et colonnes ; les index avec options ou flags sont refusés afin de ne pas perdre leur sémantique. Le garde-fou DBAL continue de refuser les modifications et suppressions. Le renderer ne crée aucun fichier, n'exécute rien et n'est pas encore raccordé à la commande. Le test réel DBAL est ignoré lorsque Doctrine n'est pas installé dans le Tooling isolé ; il reste à valider dans le projet hôte, notamment sur PostgreSQL et MySQL.

### Validation du PHP produit

Un test supplémentaire passe le fichier PHP généré au lint lorsque Doctrine DBAL est disponible. Les valeurs par défaut des colonnes non scalaires sont refusées plutôt que sérialisées implicitement. Ces tests ne remplacent pas une exécution des migrations sur les moteurs réels.

## Orchestration du générateur (sans commande)

`ModuleSchemaMigrationGenerator::generate()` relie désormais les composants existants dans l'ordre : résolution du module, découverte du registre, collecte du schéma ORM, comparaison DBAL sécurisée, rendu PHP puis écriture exclusive de la source. Si le diff est vide, aucun fichier n'est créé. Le timestamp du nom de fichier est partagé avec celui de la classe générée. La commande publique CLI reste à raccorder ; cette orchestration ne compile ni n'exécute les migrations et ne modifie pas la base. Les tests d'intégration avec un véritable EntityManager et les crash-tests restent indispensables.

## Commande Tooling interne

La commande Symfony `symsvel:module:migrate [module]` appelle l'orchestrateur avec le `ManagerRegistry` Doctrine du projet hôte. Options : `--workspace` et `--cwd` (répertoire d'origine transmis par la CLI). Sans changement de schéma, elle ne produit pas de fichier. Elle ne compile ni n'exécute la migration. La CLI publique (PR #15) délègue à cette commande Symfony interne. Le service doit être déclaré explicitement dans `SymSvelToolingBundle::loadExtension()` pour être découvert par Symfony. Son fonctionnement avec l'EntityManager réel et les différents SGBD doit encore être testé.

### Compatibilité avec la CLI draft #15

La CLI publique préexistante transmet `--cwd=<répertoire initial>` ; la commande Tooling accepte exactement cette option, sans changer la résolution de module ni dupliquer la logique CLI. Les deux PR doivent être validées ensemble sur un workspace avant merge.

## Table technique Messenger exclue des migrations de modules

Le transport Doctrine Messenger peut ajouter automatiquement `messenger_messages` au schéma retourné par `SchemaTool::getSchemaFromMetadata()` via `postGenerateSchema`, même lorsque seules les métadonnées d'un module sont fournies. `ModuleDesiredSchema` retire explicitement **cette seule table** du schéma cible avant de calculer les noms de tables et de vérifier leur propriété. Les autres tables restent soumises aux contrôles d'exclusivité, de clés étrangères et de diff existants. Le filtre ne modifie pas la base, ne produit pas de SQL et ne configure pas la création de la table technique Messenger : celle-ci relève du socle/transport. Test de non-régression dédié : table technique présente ou absente, table métier préservée. Validation CI et crash-test XT requis.

## Préservation des namespaces PostgreSQL dans le diff ciblé

L'introspection DBAL peut conserver le namespace PostgreSQL `public` dans le schéma réel alors que le schéma ORM construit depuis un seul module ne le déclare pas. Après restriction des tables, `ModuleSchemaScope` recopie dans le schéma cible de comparaison les namespaces déjà présents dans le schéma réel. Cela évite qu'un diff de module propose artificiellement `DROP SCHEMA public`. Les objets de schéma originaux ne sont pas modifiés. Le garde-fou `ModuleSchemaDiffGuard` continue de refuser toute opération réelle de création/suppression de schéma ou de séquence. Un test couvre cette normalisation ; la validation sur PostgreSQL réel reste soumise au crash-test XT.

## Génération additive multi-fichiers (12 novembre 2026, à valider)

Une comparaison peut désormais produire plusieurs fichiers sources immuables : une migration par création de table, puis une migration par table existante modifiée. Les versions sont espacées d'une seconde pour conserver un ordre chronologique déterministe. Dans le crash-test TestCategory/TestRecord, le résultat attendu est une création de `symsvel_test_categories`, puis l'ajout de `category_id`, de son index et de sa clé étrangère sur `symsvel_test_records`. La commande interne affiche chaque fichier créé ; la CLI reste l'entrée publique.

Les ajouts de colonnes, d'index simples et de clés étrangères sont pris en charge ; les suppressions, changements de types, changements de contraintes et formes de diff DBAL inconnues restent refusés. Une nouvelle colonne NOT NULL sans valeur par défaut est refusée. Le renderer crée les opérations inverses dans `down()`, sans prétendre restaurer les données éventuellement supprimées lors d'un rollback. Les sources sont toutes rendues avant la première écriture et les fichiers déjà écrits sont nettoyés si une écriture suivante échoue.

**État : implémentation sur `dev`, non validée en CI ni sur XT.** Les créations de tables avec clés étrangères directes restent volontairement refusées par le renderer initial. Les références vers une table nouvellement créée doivent être traitées dans une migration ultérieure. Crash-tests PostgreSQL puis MySQL requis avant promotion vers `main`.

### Fichiers de métadonnées macOS

La découverte des migrations ignore explicitement `.DS_Store` (Finder) et `.gitkeep` dans les dossiers `Migration/`, tant pour les modules du workspace que pour les modules Composer. Les autres fichiers non conformes restent refusés : cette tolérance ne désactive pas la validation des sources PHP.

## Évolution des migrations destructrices (12 novembre 2026, chantier en cours)

**Décision validée :** `symsvel module:migrate` se comporte comme `make:migration` à l'échelle d'un module. La modification ou disparition d'une entité ORM est intentionnelle ; la génération du fichier est une action explicite du développeur et ne demande pas de confirmation destructive supplémentaire. La génération n'exécute jamais le SQL. Les anciennes sections de ce document qui interdisent les suppressions décrivent l'état historique, désormais remplacé par cette décision.

Le périmètre du diff inclut maintenant **toutes les tables déclarées dans `module.json`**, même si leur entité ORM a été supprimée. Les tables des autres modules restent exclues. Le contrôle des FK entrantes utilise également la liste complète des tables possédées, pour ne pas masquer les références externes vers une table supprimée.

Le garde-fou accepte les suppressions de tables et les modifications de colonnes, suppressions de colonnes, d'index simples et de FK ; les formes inconnues, les modifications d'index ou de FK non représentées et les opérations sur schémas/séquences restent refusées. Le renderer produit des opérations Schema inverses : restauration de la structure de colonne/table/FK dans `down()`, **sans restauration des données supprimées**. Une conversion inverse de type peut échouer si les données ne sont plus compatibles.

Le générateur ordonne les créations de tables, les modifications de tables existantes puis les suppressions de tables. Les contraintes entrantes depuis d'autres modules restent bloquantes. Les FK directes dans une table créée ou recréée sont encore refusées par le renderer : les cas complexes devront être traités et testés séparément. Les conversions de type dépendent de la plateforme DBAL ; les tests PostgreSQL/MySQL et le crash-test XT sont indispensables avant toute validation ou promotion vers `main`.

**Statut : implémentation initiale sur `dev`, validation fonctionnelle et CI non encore établies.**

### Dernière entité supprimée

`ModuleDesiredSchema` accepte désormais une sélection de métadonnées ORM vide et retourne un schéma Doctrine vide. Cette situation correspond notamment à la suppression de la dernière entité du module ; les tables possédées restent visibles du côté base grâce à `ModuleSchemaScope` et peuvent donc générer leurs migrations de suppression. Le comportement nécessite encore un test d'intégration réel.

### Alignement des tests du garde-fou (12 novembre 2026)

Les tests `ModuleSchemaDiffGuardTest` utilisent désormais `assertSupported()` et attendent l'acceptation des suppressions de tables et colonnes, conformément à la décision ORM faisant foi. Les refus de diff DBAL inconnu et d'opérations sur schémas/séquences restent testés. Ce correctif concerne les tests, sans réintroduire l'ancienne interdiction des opérations destructrices.

### Signature DBAL 4 de modification de colonne (12 novembre 2026)

La génération d'un changement de type produit `Table::modifyColumn($name, ['type' => Type::getType($type), ...$options])` et non la signature à trois arguments de `addColumn()`. Le type est un objet Doctrine Type ; la migration source est générée sans exécuter la modification. Une régression vérifie les instructions `up()` et `down()`.

### Index introspectés avec `lengths: [null]` (12 novembre 2026)

Un index associé à une relation ORM peut être introspecté par DBAL avec `getOptions() === ['lengths' => [null]]`. Lorsque `lengths` contient uniquement des valeurs nulles, non vide et de même cardinalité que les colonnes de l'index, le renderer traite cette option comme une absence de longueur de préfixe et peut générer l'ajout ou la suppression de l'index. Les flags et toute autre option restent refusés tant que leur restitution n'est pas prise en charge ; une longueur de préfixe réelle (ex. `12`) reste interdite. Ce comportement s'applique aux index de tables créées, ajoutés et supprimés, y compris leur restauration dans `down()`. Tests automatisés ajoutés ; validation CI et crash-test PostgreSQL/MySQL à confirmer.

## Observation temporaire du premier diff Doctrine au setup (CT XT, 12 novembre 2026)

Sur la branche `dev`, `symsvel:setup:database` lance provisoirement `make:migration --no-interaction` après la création de la base et avant la compilation/exécution des migrations des modules. L'objectif est de vérifier quelles entités Doctrine détecte réellement à ce stade. Le fichier natif `backend/migrations/VersionYYYYMMDDHHMMSS.php` est **généré mais non exécuté** par cette étape. Le nom est enregistré dans `.symsvel/native-migration-observation.json` pour éviter une nouvelle génération lors d'une reprise. Le setup continue ensuite avec les migrations des modules et l'écran du premier administrateur permet l'inspection manuelle du fichier.

Ce test attend une seule migration native dans le dossier au moment de la génération. Il exige MakerBundle et n'est **pas** une décision de production ni une validation de compatibilité : si Doctrine inclut les tables des modules, la migration ne doit pas être exécutée. Après inspection XT, décider de la création ciblée de `messenger_messages`, de la gestion du fichier natif et de la suppression de ce dispositif temporaire. Les tests et la CI ne remplacent pas le crash-test utilisateur.

## Migration Doctrine résiduelle après les modules (CT du 12 novembre 2026)

Le crash-test PostgreSQL a montré que `make:migration` avant les migrations Schema des modules génère à tort les tables des quatre modules et Messenger. Après exécution des migrations des modules, le diff Doctrine ne contient plus que `messenger_messages` et son index.

Sur `dev`, `symsvel:setup:database` exécute désormais, dans cet ordre : création de la base si nécessaire, compilation/exécution des migrations des modules, `make:migration --no-interaction`, puis `doctrine:migrations:migrate --no-interaction`. La migration native est donc générée à partir du schéma réellement installé, et non avant les modules. L'ancienne observation préliminaire n'est plus produite.

Pour éviter de rejouer ou d'exécuter une migration native étrangère, la première génération refuse les fichiers `backend/migrations/Version*.php` préexistants. Un marqueur `.symsvel/native-migration-setup.json` enregistre le fichier généré (ou l'absence de diff) et permet de reprendre après un échec de `migrate` sans régénérer. Le marqueur doit correspondre exactement aux fichiers natifs présents. Les migrations des modules restent sous leur responsabilité ; la migration Doctrine complémentaire concerne les objets techniques du projet hôte.

**Statut :** implémentation sur `dev` ; CI et nouveau crash-test utilisateur requis. Ne pas promouvoir vers `main` ni publier de release avant leur validation. La migration native reste une opération Doctrine générée automatiquement : inspecter tout diff inattendu, en particulier les opérations destructrices, avant validation pour la production.

### Correctif CT : migrations compilées déjà présentes

Les migrations Schema des modules sont compilées dans `backend/migrations/Version*.php` avant `make:migration`. Le refus initial de tout fichier préexistant bloquait donc systématiquement le setup. Le contrôle prend désormais un instantané des fichiers avant la génération et identifie le **nouveau fichier uniquement** par différence ; les migrations compilées restent présentes. En reprise, le marqueur natif vérifie l'existence du fichier généré sans exiger que ce soit le seul fichier du répertoire. Le test simule une migration compilée et une migration Doctrine complémentaire. Validation CI et nouveau CT requis.

## Setup : migrations techniques du squelette

Le setup exécute les migrations des modules puis `doctrine:migrations:migrate`, qui applique aussi les migrations versionnées du squelette Symfony. Il ne lance plus `make:migration` : le diff ORM global pouvait générer des `DROP TABLE` pour les tables de modules non mappées comme entités ORM. La table du transport Doctrine Messenger est créée par `symsvel-back/migrations/Version20000000000000.php`. Aucun marqueur `native-migration-setup.json` n'est nécessaire.

## Diagnostic des échecs de compilation au Setup (CT du 17 novembre 2026)

`symsvel:setup:database` lance `symsvel:module:migrations:compile --source=composer --execute` dans un processus enfant. Lors d'un CT MySQL 8.4.5, la création de la base `xt12345` avait réussi, mais la compilation renvoyait le code 1 ; l'ancienne erreur générique de Setup masquait la sortie d'erreur et la sortie standard du compilateur. Les entrées `console.DEBUG` du `dev.log` ne conservent que les codes de sortie, pas le diagnostic original.

Tooling inclut désormais la commande enfant, son code de sortie et ses messages dans l'échec de `symsvel:setup:database`. Le contrôleur du Setup local dans Back est déjà chargé de masquer les URL de connexion et mots de passe avant de présenter cette sortie au navigateur. Le test `SymSvelSetupDatabaseCommandTest::testFailedCompilerErrorIsVisibleForSetupDiagnostics` vérifie cette propagation. **La cause SQL/Doctrine de l'échec du CT n'est pas établie par les logs reçus** ; ne pas déduire qu'elle est résolue par ce seul correctif de diagnostic.

Avant reprise du Setup, vérifier la présence d'un journal `.symsvel/migration-recovery.json` : lorsqu'il existe, le mécanisme de récupération doit être utilisé et aucune relance brute de la compilation ne doit contourner cette sécurité. La CI, puis un nouveau CT utilisateur sont nécessaires.

## Réservation unique des versions Doctrine (CT du 17 novembre 2026)

**Source de référence mainteneur :** `docs/modules/migration-versions.json` dans `symsvel-tooling/dev`. Le fichier recense les **24 fichiers de migration présents** dans `symsvel-back` et les modules natifs à cette date, en associant chaque `VersionYYYYMMDDHHMMSS` à son dépôt et à son chemin. Il ne remplace ni les reçus d'exécution `.symsvel/migrations.json` de chaque projet ni `doctrine_migration_versions`.

**Procédure obligatoire avant toute nouvelle migration :** vérifier l'inventaire global, réserver un numéro unique, créer la source dans le module propriétaire et ajouter dans le même chantier l'entrée `version/repository/path` au registre versionné. Lors d'une évolution, comparer le registre à la liste effective des fichiers source sur les branches `dev` des dépôts concernés, car une vérification interne du JSON seul ne garantit pas leur synchronisation. Ne jamais changer une version déjà enregistrée dans un projet sans procédure explicite de compatibilité.

**Incident corrigé :** Admins et Users ont initialement créé deux fichiers `Version20261117000001.php`, alors qu'ils ajoutent `language` sur des tables distinctes. Admins conserve `Version20261117000001` ; Users utilise `Version20261117000002`. La rectification Users intervient avant validation du CT de développement et exige la reprise sécurisée d'un éventuel projet dont la version aurait été enregistrée.

**Détection avant compilation :** `ModuleMigrationDiff::compare()` refuse désormais deux migrations de modules avec la même version, avant l'écriture des fichiers compilés. Le test `ModuleMigrationVersionCollisionTest` protège ce contrôle et vérifie l'unicité des entrées du registre. La CI doit passer au vert puis l'utilisateur doit valider un nouveau CT MySQL ; la cause exacte du premier échec reste à confirmer sur le projet.
