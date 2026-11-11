# Génération des migrations Schema par module

## Objectif validé (11 novembre 2026)

La commande publique cible est `symsvel module:migrate [MonModule]`.
Elle génère une **nouvelle migration source PHP** dans le dossier `Migration/` du module, au format `VersionYYYYMMDDHHMMSS.php`, avec les corps `up(Schema $schema)` et `down(Schema $schema)`.
La compilation, l'exécution et le registre existants restent la responsabilité du Tooling. La génération ne doit pas modifier une migration source existante ni écrire directement dans `backend/migrations/`.

## Résolution du module : première étape implémentée

`ModuleWorkingDirectoryResolver` s'appuie sur `ModuleRegistry` et `ModuleManifest`, déjà présents. Un argument explicite peut être le slug ou le nom PascalCase du manifeste. Sans argument, le répertoire courant doit se trouver dans le dossier du module, y compris un sous-répertoire. Un contexte ambigu ou extérieur à un module est refusé. Le chemin canonique est vérifié par `realpath` et la comparaison respecte les frontières de répertoire.

**État :** le résolveur, un sélecteur de métadonnées ORM par emplacement physique des classes et leurs premiers tests sont ajoutés. Le sélecteur ne construit pas encore de schéma ni de diff. La commande publique, la collecte des métadonnées ORM, le diff, le renderer PHP et la génération du fichier ne sont **pas encore implémentés**. Aucun crash-test n'est revendiqué.

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
