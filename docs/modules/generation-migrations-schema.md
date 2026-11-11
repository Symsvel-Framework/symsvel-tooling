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

`ModuleSchemaMigrationRenderer::render()` produit une classe `VersionYYYYMMDDHHMMSS` avec `up(Schema $schema)` et `down(Schema $schema)` pour les **créations de tables simples** seulement. Les colonnes et clés primaires sont représentées avec l'API Doctrine Schema ; le `down()` supprime les tables nouvellement créées en ordre inverse. Le renderer refuse les clés étrangères et index secondaires jusqu'à leur prise en charge explicite. Le garde-fou DBAL continue de refuser les modifications et suppressions. Le renderer ne crée aucun fichier, n'exécute rien et n'est pas encore raccordé à la commande. Le test réel DBAL est ignoré lorsque Doctrine n'est pas installé dans le Tooling isolé ; il reste à valider dans le projet hôte, notamment sur PostgreSQL et MySQL.
