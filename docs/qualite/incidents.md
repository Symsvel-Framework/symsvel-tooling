# Diagnostic et récupération

## Création de projet interrompue

La CLI conserve les fichiers présents pour permettre le diagnostic. Identifier l'étape échouée avant toute suppression manuelle.

## Setup interrompu

Reprendre le Setup Wizard local avec `symsvel setup`. Un formulaire réinitialisé ne signifie pas qu'une base de données a été restaurée.

## Ressource de module modifiée

Le Tooling peut refuser une mise à jour ou une suppression si un fichier géré a été modifié. Identifier le conflit avant de relancer.

## Rollback incomplet

Une récupération incomplète est signalée ; les checkpoints sont conservés. Ne pas les nettoyer avant d'avoir terminé la récupération.

Voir [transactions et checkpoints](transactions.md).

## Setup local : échec de build BO pendant la finalisation (09/11/2026)

Lors du CT XT PostgreSQL, `npm run build` fonctionne depuis les terminaux de l'installateur (Node 22.22.2, npm 10.9.7), mais le sous-processus `symsvel:setup:complete` lancé par le navigateur signale un échec du build BO. La cause précise n'est pas encore établie. La commande de finalisation transmet désormais la sortie standard, la sortie d'erreur et le code de retour de npm à son appelant, afin que le Setup local puisse afficher un diagnostic exploitable. L'état `database_ready` est conservé en cas d'échec, sans relancer les migrations. Tests automatisés et reprise du CT XT obligatoires avant clôture.
