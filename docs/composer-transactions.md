# Transactions Composer SymSvel (expérimental)

Le lanceur transactionnel est **optionnel** : les commandes natives `composer require`, `composer update` et `composer remove` ne sont **pas interceptées**. Le script est publié dans `backend/scripts/` par `symsvel:update`.

Depuis `backend/` :

```bash
php scripts/symsvel-composer-transaction.php require symsvel-framework/module-test-back:dev-main
php scripts/symsvel-composer-transaction.php update symsvel-framework/module-test-back
php scripts/symsvel-composer-transaction.php remove symsvel-framework/module-test-back
```

## Périmètre et prérequis

Le lanceur exige un `composer.lock` initial et crée un checkpoint dans `backend/.symsvel/transactions/<id>/`. Il sauvegarde :

- les manifests `composer.json`, `composer.lock` et `symfony.lock` s'il existe ;
- les fichiers sources du workspace BACK/BO/APP/FRONT (hors dossiers de dépendances, builds et Git) ;
- `backend/vendor/` et les artefacts `node_modules/`, `build/`, `dist/`, `.svelte-kit/` des interfaces présentes.

La commande Composer est exécutée avec `--no-scripts --no-plugins` ; ces options ne peuvent pas être fournies directement au lanceur. Les options modifiant le répertoire de travail sont également refusées.

## Échec et restauration

Si Composer échoue, le lanceur tente de restaurer les manifests, `vendor/`, les sources et les artefacts des interfaces. Il compare ensuite les empreintes des fichiers sources à leur état initial. Si une restauration ou une vérification échoue, la commande signale une **récupération incomplète** et conserve le checkpoint. Elle ne prétend pas avoir restauré ce qu'elle n'a pas pu vérifier.

**Limites importantes :** le lanceur Composer ne sauvegarde **pas** la base de données, ne restaure pas les références Git et ne compense pas les effets sur des services externes. Il ne remplace donc pas le mécanisme global de `symsvel:update`. En particulier, un script tiers ou une action manuelle hors de son périmètre ne bénéficie pas d'une transaction complète.

Après succès, le lanceur inscrit son checkpoint dans `backend/.symsvel/cleanup-pending.json` **avant** de lancer son nettoyage. Comme pour `symsvel:update`, une suppression interrompue est reprise par `php bin/console symsvel:cleanup` (éventuellement via cron). Si l'enregistrement échoue, l'opération Composer reste réussie, le checkpoint est conservé et un avertissement est émis via le suivi de progression. Les checkpoints d'une récupération incomplète ne sont jamais inscrits au registre.

## Mise à jour globale du workspace

`APP_ENV=core php bin/console symsvel:update` et son équivalent `dev` utilisent un mécanisme distinct : checkpoints Git, Composer, sources, dépendances, builds et **base de données**. PostgreSQL et SQLite sont pris en charge ; une `DATABASE_URL` valide est obligatoire. Les échecs de restauration sont signalés et leurs checkpoints conservés.

Après une **mise à jour globale réussie**, les checkpoints sont inscrits dans `backend/.symsvel/cleanup-pending.json`. Le nettoyage peut être repris par :

```bash
php bin/console symsvel:cleanup
```

Cette commande est conçue pour être exécutée périodiquement par cron, mais aucun cron n'est installé automatiquement. Elle ne doit jamais supprimer les checkpoints d'un rollback incomplet.

Voir également [le README](../README.md).
