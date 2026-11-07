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
- les fichiers sources du workspace BACK/BO/APP/FRONT, y compris les inventaires `.symsvel` (hors checkpoints transactionnels, registre de nettoyage, dépendances, builds et Git) ;
- `backend/vendor/` et les artefacts `node_modules/`, `build/`, `dist/`, `.svelte-kit/` des interfaces présentes.

La commande Composer est exécutée avec les plugins Flex et les scripts Composer actifs, afin d'inclure les recettes et la synchronisation SymSvel dans la transaction. Les options `--no-scripts` et `--no-plugins` sont refusées par le lanceur. Les options modifiant le répertoire de travail sont également refusées.

## Échec et restauration

Si Composer échoue, le lanceur tente de restaurer les manifests, `vendor/`, les sources et les artefacts des interfaces. Il compare ensuite les empreintes des fichiers sources à leur état initial. Si une restauration ou une vérification échoue, la commande signale une **récupération incomplète** et conserve le checkpoint. Elle ne prétend pas avoir restauré ce qu'elle n'a pas pu vérifier.

**Limites importantes :** le lanceur Composer peut créer un checkpoint global de la base lorsque `DATABASE_URL` est explicitement disponible ; cette sauvegarde prend en charge PostgreSQL et SQLite. En l'absence de configuration BDD, aucune restauration globale de la base ne peut être garantie. Il ne restaure pas les références Git et ne compense pas les effets sur des services externes. Il ne remplace donc pas le mécanisme global de `symsvel:update`. En particulier, un script tiers ou une action manuelle hors de son périmètre ne bénéficie pas d'une transaction complète.

Après succès, le lanceur inscrit son checkpoint dans `backend/.symsvel/cleanup-pending.json` **avant** de lancer son nettoyage. Comme pour `symsvel:update`, une suppression interrompue est reprise par `php bin/console symsvel:cleanup` (éventuellement via cron). Si l'enregistrement échoue, l'opération Composer reste réussie, le checkpoint est conservé et un avertissement est émis via le suivi de progression. Les checkpoints d'une récupération incomplète ne sont jamais inscrits au registre.

## Mise à jour globale du workspace

`php bin/console symsvel:update` (en environnement Symfony `dev`) utilise un mécanisme distinct : checkpoints Git, Composer, sources, dépendances, builds et **base de données**. PostgreSQL et SQLite sont pris en charge ; une `DATABASE_URL` valide est obligatoire. Les échecs de restauration sont signalés et leurs checkpoints conservés.

Après une **mise à jour globale réussie**, les checkpoints sont inscrits dans `backend/.symsvel/cleanup-pending.json`. Le nettoyage peut être repris par :

```bash
php bin/console symsvel:cleanup
```

Cette commande est conçue pour être exécutée périodiquement par cron, mais aucun cron n'est installé automatiquement. Elle ne doit jamais supprimer les checkpoints d'un rollback incomplet.

Voir également [le README](../README.md).

## Provenance des nouveaux modules en canal dev

Pour un projet dont `backend/.symsvel/project.json` déclare `channel: dev`, la transaction `require symsvel-framework/module-*` prépare désormais le dépôt Git officiel `https://github.com/Symsvel-Framework/symsvel-module-*.git` **après la prise du checkpoint Composer et avant le require**. L'entrée `repositories.module-<nom>-dev` est vérifiée lorsqu'elle existe déjà : une définition incompatible provoque un échec sans écrasement. Les dépendances tierces et les opérations `update`/`remove` ne déclenchent aucune déclaration de dépôt.

En cas d'échec, `composer.json` est restauré par le mécanisme transactionnel existant ; un test vérifie ce scénario par injection d'une erreur Composer. Le bootstrap initial est désormais délégué au même lanceur transactionnel par la CLI en cours de refonte (PR #10) ; sa déclaration VCS n'est plus effectuée avant le checkpoint. La résolution réelle via GitHub/Satis et les crash-tests XT restent à valider.

### Fixtures de bootstrap (07/11/2026)

Les tests de bootstrap des modules créent explicitement un canal `stable` lorsque la transaction atteint la résolution des sources. Cela évite de confondre une fixture sans provenance avec un défaut de la logique métier. Le nettoyage des fixtures retire également `backend/.symsvel/project.json`. Le résultat CI de ce correctif reste à confirmer.

### Intégration VCS Git locale (07/11/2026)

Un test d'intégration supplémentaire initialise un vrai dépôt Git local avec un package `symsvel-framework/module-demo` sur `main`. Le lanceur transactionnel déclare le dépôt officiel puis l'injection de commande substitue uniquement l'URL par la fixture locale pour éviter le réseau. Composer effectue un vrai `require` en `dev-main`, suivi d'un échec injecté ; le test vérifie la restauration byte-à-byte de `composer.json` et `composer.lock` et l'absence du module dans `vendor`. Ce test ne prouve pas encore l'accès au dépôt GitHub réel. CI et crash-tests XT à confirmer.

### Dépôts VCS déjà déclarés

Deux tests unitaires supplémentaires contrôlent la réutilisation sans mutation d'un dépôt officiel identique et le rejet d'un dépôt de même clé pointant vers une autre URL, avant tout `composer require`. Le rejet préserve le manifeste original. La CI doit confirmer ces scénarios.
