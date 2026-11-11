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
