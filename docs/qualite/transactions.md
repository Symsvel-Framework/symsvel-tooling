# Transactions, checkpoints et restauration

Le Tooling encadre des opérations locales avec préconditions, checkpoints, tentatives de restauration indépendantes et vérifications. Composer, fichiers, npm et base de données **ne forment pas une transaction atomique unique**.

## Distinguer les parcours

- `symsvel require/update/remove <package>` : lanceur Composer transactionnel de Tooling, non appliqué aux commandes Composer natives.
- `symsvel update bo|app|front` : installation ou mise à jour native du composant, avec manifeste de provenance, analyse des conflits et checkpoints.
- `php bin/console symsvel:update` : `WorkspaceUpdater`, parcours global distinct. Ne pas le présenter comme synonyme de `symsvel update bo` ou d'une mise à jour Composer sélective.

## Garanties et limites

Le lanceur Composer exige un `composer.lock` initial, conserve les manifests et les sources, `vendor/` et les artefacts pertinents des interfaces. Flex et scripts Composer sont exécutés ; les options qui les désactivent sont refusées. Une sauvegarde globale BDD est possible avec `DATABASE_URL` exploitable et un moteur pris en charge (PostgreSQL ou SQLite). Sans sauvegarde BDD effective, **ne pas promettre** de restauration de la base.

La mise à jour native des interfaces compare trois états : référence native installée, source amont et fichiers locaux. Elle refuse les collisions et n'impose ni propreté Git ni fusion Git des dépôts du projet. Voir [le contrat technique](../component-updates-v1.md).

En cas d'échec, les restaurations possibles sont tentées et vérifiées indépendamment. Un rollback incomplet est signalé ; les checkpoints correspondants restent disponibles pour diagnostic et ne doivent jamais être inscrits au nettoyage différé. Les opérations sur services externes et les références Git ne sont pas restaurées par ces snapshots.

Après une opération réussie, le registre `backend/.symsvel/cleanup-pending.json` permet de reprendre le nettoyage via `php bin/console symsvel:cleanup`. Aucun cron n'est installé automatiquement.

## Statut de validation

Les tests automatisés utilisent notamment des dépôts Git locaux pour éprouver Composer et les sources natives. Ils ne prouvent pas l'accès aux dépôts GitHub/Satis officiels ni le parcours complet CLI/Setup Wizard. La CI des PR est un jalon ; les crash-tests XT et les scénarios de rollback dégradé restent requis avant validation finale.

Voir [le lanceur Composer](../composer-transactions.md) et [le diagnostic](incidents.md).
