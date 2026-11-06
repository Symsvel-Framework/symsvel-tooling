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
