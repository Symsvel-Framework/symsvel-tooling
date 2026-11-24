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

## Synchronisation des paramètres de modules (en cours)

Après les migrations d'une transaction Composer disposant d'une base configurée et du module Settings, `ComposerTransactionRunner` rafraîchit l'inventaire Composer via `symsvel:module:sync --source=composer`, puis appelle la commande interne `symsvel:settings:sync`. Cette dernière est fournie par Settings et gère les lignes de `symsvel_settings` et `backend/.symsvel/modules-params.json`. Ce branchement ne couvre pas encore la finalisation du Setup initial sans `DATABASE_URL` lors du bootstrap, ni tous les scénarios de récupération. La commande Symfony interne n'est pas une nouvelle interface utilisateur publique. Validation CI et CT XT requise.

En cas d'échec, `symsvel:setup:database` indique l'étape, le SAPI, le binaire PHP et la classe et localisation de l'exception dans sa sortie de diagnostic. En production, consulter le journal privé du Setup, sans publier de secrets.

La préparation initiale de la base (`symsvel:setup:database`) synchronise les paramètres après les migrations Doctrine natives, avant `DATABASE_READY`. La finalisation historique (`symsvel:setup:finalize`) les synchronise après les migrations. Pour Composer, l'inventaire des modules est rafraîchi avant le chargement du conteneur Symfony, puis les paramètres après les migrations. La synchronisation reste conditionnelle à Settings et à une BDD configurée. Les CI et les crash-tests XT restent requis avant validation.

Les tests de transaction Composer vérifient maintenant l'appel explicite de rafraîchissement de l'inventaire après Composer, y compris lorsque la transaction ne comporte pas de migration. Les attentes historiques qui ne comptaient que l'appel Composer ont été ajustées. La commande de synchronisation des paramètres reste conditionnelle à la présence du module Settings et à une connexion BDD configurée.

### Visibilité des tests ignorés en CI

La CI du Tooling lance PHPUnit avec `--display-skipped` en plus de `--display-warnings --fail-on-warning`. Les motifs des tests ignorés apparaissent dans les logs du job PHPUnit pour distinguer les prérequis absents des exclusions à corriger. Ce réglage ne change ni l'exécution ni le statut des tests ignorés.

## Publication des métadonnées Composer et droits locaux (CT App du 17/11/2026)

La transaction Composer de Tooling publie `backend/.symsvel/modules.json` (inventaire) et `backend/.symsvel/bundles.php` (classes de bundles) via un fichier temporaire et renommage atomique. `tempnam()` crée ce fichier avec des permissions restrictives `0600` : après installation depuis le compte CLI, un processus PHP local exécuté sous un autre utilisateur ne pouvait pas lire le registre et générait `file_get_contents(.../backend/.symsvel/modules.json): Permission denied` lors du CT d'inscription App.

Les deux écrivains Composer publient maintenant les **seules métadonnées techniques non secrètes** en mode `0644`, avant le renommage atomique. Lors d'une publication identique, ils restaurent également ces permissions pour corriger un fichier antérieurement généré en `0600`. Les répertoires `.symsvel` doivent rester traversables par l'utilisateur PHP. Cela ne modifie pas les permissions des secrets (`.env.local`), des sauvegardes et des journaux de récupération, qui doivent rester protégés. La logique d'inscription Users/App n'est pas modifiée.

`ComposerModulePlannerTest::testComposerMetadataIsReadableAfterAtomicPublicationAndReuse` couvre la publication et le cas du fichier ancien en `0600`. L'origine exacte de l'appel HTTP au registre d'outillage reste à tracer indépendamment : l'interface publique ne doit pas exécuter une opération de maintenance pour gérer une inscription. CI et CT multi-utilisateurs requis avant clôture ; éviter un `chmod -R` du workspace.


## Publication locale des dictionnaires de traduction (17/11/2026)

\`SymSvel\Tooling\I18n\TranslationFilePublisher\` est l'unique écrivain des fichiers TypeScript générés. Le module Languages reste propriétaire des clés et des textes en BDD, de la génération du contenu et de l'export CSV. Tooling reçoit seulement le backend du projet, le scope \`bo|app|front\`, la langue et le contenu déterministe produit par Languages ; il ne contient aucune logique métier ni analyse de traductions.

L'API de publication BO est uniquement disponible en environnement Symfony \`dev\`, aux requêtes locales (loopback), derrière les ACL \`languages.manage\`. Aucun endpoint d'écriture de sources ne doit être publié en production. L'écrivain vérifie les composants installés, les répertoires réels et l'absence de liens symboliques dans le chemin cible avant d'écrire \`<workspace>/<scope>/src/lib/i18n/generated/<language>.ts\`. Un fichier existant doit contenir la signature de génération SymSvel, sinon l'écriture est refusée. L'écriture est atomique (temporaire + renommage), idempotente et ne lance pas de build. Les chemins hors composants connus et les codes langue non conformes sont refusés. Les secrets et autres fichiers de l'application ne sont jamais traités.

Le test \`TranslationFilePublisherTest\` contrôle publication, remplacement, absence de changement, refus de fichier personnalisé, symlinks et traversée de répertoires. La synchronisation des composants et la publication des traductions sont deux registres indépendants : le synchroniseur des sources de modules ne doit pas supprimer les traductions générées. Après validation du CT, la CD build SvelteKit à partir des fichiers versionnés ; une simple écriture n'actualise pas les bundles existants. Le mapping de dossiers renommés n'est pas encore pris en charge par ce premier écrivain, qui exige le répertoire \`backend\` et les composants nommés \`bo/app/front\` au même niveau.
