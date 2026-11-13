# Commandes des modules SymSvel

La CLI `symsvel` est le point d'entrée utilisateur pour installer, mettre à jour ou retirer des packages. Les commandes Symfony du Tooling servent à la synchronisation, aux migrations, aux plans et à la récupération. **Composer/Flex est intégré au code**, mais la résolution réseau officielle et le parcours XT complet restent à valider.

## Parcours utilisateur

```bash
symsvel make:module documents --scopes=back,bo,app
symsvel require symsvel-framework/module-test-back
symsvel update symsvel-framework/module-test-back
symsvel remove symsvel-framework/module-test-back
```

Le canal `dev` ou `stable` vient de `backend/.symsvel/project.json`, pas de `APP_ENV`. La CLI délègue les opérations Composer au lanceur transactionnel du Tooling. Les contraintes explicites fournies à `require` restent prioritaires.

## Commandes Symfony internes

Depuis `backend/`, préfixer les commandes par `php bin/console` :

| Commande | Fonction |
| --- | --- |
| `symsvel:make:module <name>` | Génération avec ModuleMaker |
| `symsvel:module:plan` | Prévisualisation des modules |
| `symsvel:module:sync` | Inventaire et carte des Bundles |
| `symsvel:module:sync:svelte` | Synchronisation des ressources Svelte |
| `symsvel:module:migrations:compile` | Compilation des migrations |
| `symsvel:module:migrations:recovery:status` | Diagnostic d'une récupération |
| `symsvel:module:migrations:recover` | Récupération des migrations |
| `symsvel:module:remove:plan <module>` | Plan de suppression et dépendants |
| `symsvel:module:remove:migrations:plan <module>` | Préparation de la suppression SQL |
| `symsvel:module:remove:migrations:execute <module>` | Suppression SQL après confirmation |
| `symsvel:module:remove:recovery:status` | Diagnostic de récupération après retrait |

Les signatures exactes et options sont fournies par `php bin/console <commande> --help`. Un plan ou un statut ne doit pas être confondu avec une exécution. Les sources de migration sont collectées auprès des modules et triées globalement par nom de fichier avant compilation. Cette préparation ne modifie pas l'orchestration Doctrine. Les migrations sont actuellement ordonnées selon les dépendances lors de la planification des modules ; la suppression destructrice nécessite les confirmations et protections du Tooling. Après incident, consulter les états de récupération avant une nouvelle opération.

Le Maker produit un **modèle** de recette Flex ; sa publication dans `symsvel-recipes` et son référencement sont distincts. Ne pas développer directement dans `vendor/`.

Voir [le contrat des modules](contrat.md) et [les transactions Composer](../composer-transactions.md).

### Contrat des versions de migration (chantier en cours)

Chaque fichier source doit être nommé `VersionYYYYMMDDHHMMSS.php`. Le compilateur conserve cet identifiant dans `backend/migrations/` et refuse une version déjà présente au lieu de la renommer. Les fichiers historiques `001_*.php` des modules natifs doivent être normalisés avant utilisation de cette branche. Le compilateur génère une migration Doctrine qui compare le schéma réel de la connexion DBAL avec une copie modifiée par les opérations `Schema` du module, puis transmet les instructions SQL adaptées à la plateforme à `addSql()`. Les migrations de données conservent leurs appels `addSql()`. **Ce mécanisme doit encore être validé en intégration sur PostgreSQL et MySQL avant tout merge.** Les fichiers historiques `001_*.php` restent incompatibles tant que les modules natifs ne sont pas normalisés.

## Génération depuis le workspace Framework

Depuis une racine contenant les dépôts frères `symsvel-cli/` et `symsvel-tooling/`, `symsvel make:module mail --scopes=back,bo` crée `symsvel-module-mail/` directement à la racine. Le nom Composer reste `symsvel-framework/module-mail`. Le Maker accepte également `symsvel-module-mail` comme nom d'entrée. Depuis un projet utilisateur classique, le parcours historique de génération dans `modules/mail/` est conservé.
