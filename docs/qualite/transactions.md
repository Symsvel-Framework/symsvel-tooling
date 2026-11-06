# Transactions, checkpoints et restauration

Le Tooling encadre les opérations de maintenance avec des vérifications préalables, des checkpoints, des restaurations indépendantes et des contrôles après rollback. Composer, Git, npm et une base de données ne constituent pas une transaction atomique unique.

## Composer encadré

Le lanceur transactionnel exige un `composer.lock` initial. Il sauvegarde les manifests Composer, les sources du workspace, `vendor/` et les artefacts des interfaces présentes. Flex et les scripts Composer sont exécutés dans la transaction ; les options qui les désactivent sont refusées.

Lorsqu'une `DATABASE_URL` est disponible, le code prévoit également un checkpoint global de la base. Les sauvegardes globales sont implémentées pour PostgreSQL et SQLite, pas pour tous les pilotes Doctrine.

Les commandes `composer require/update/remove` exécutées directement ne sont **pas** interceptées par le lanceur SymSvel.

## Mise à jour globale

La mise à jour globale du workspace utilise un mécanisme distinct avec contrôles Git, snapshots de fichiers et dépendances, artefacts des interfaces et checkpoint BDD. Un composant Git comportant des modifications locales est refusé avant l'opération.

## Échec et récupération

Les restaurations de base, manifests, dépendances, sources et artefacts sont tentées indépendamment. Après restauration, l'état est vérifié. Une récupération incomplète est signalée explicitement et les checkpoints sont conservés.

Les checkpoints d'opérations réussies peuvent être nettoyés ultérieurement via le registre de nettoyage. Ceux d'un rollback incomplet ne doivent jamais être soumis au nettoyage automatique.

## Limites

- Les opérations sur services externes ne sont pas annulées par un snapshot local.
- La sauvegarde globale BDD ne couvre actuellement que PostgreSQL et SQLite.
- Des connexions PostgreSQL actives peuvent bloquer la restauration ; le checkpoint est alors conservé.

Voir [le diagnostic](incidents.md).
