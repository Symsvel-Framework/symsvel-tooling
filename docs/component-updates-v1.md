# Contrat de mise à jour des composants Svelte (V1)

> **Lecture de ce journal (08/11/2026) :** les sections datées retracent les étapes successives du chantier #60. Les phrases « reste à réaliser » décrivent parfois un état **historique**, pas l'état du code courant. Le parcours actif de `WorkspaceUpdater::update()` appelle désormais `WorkspaceNativePreparation` avant Composer et `WorkspaceNativeApply` après Composer ; il ne fait plus de fusion Git dans les composants du projet. Les tests d'intégration, la CI et les crash-tests XT restent des validations distinctes.

## Sélection des branches (11/11/2026)

Le canal enregistré dans `backend/.symsvel/project.json` pilote les sources Svelte : `dev` clone la branche Git `dev` pour BO/App/Front, `stable` utilise `main` lors de l'installation initiale. La récupération native pour les mises à jour en canal `dev` pointe également sur `dev` ; le canal `stable` continue de sélectionner la dernière release publiée pour les mises à jour. Les modules natifs restent sur leur branche Git `main` et leur contrainte Composer `dev-main`. Le crash-test XT doit confirmer l'ensemble des parcours avant validation finale.

## Propriété des sources

Une fois installés, les fichiers de `bo/`, `app/` et `front/` appartiennent au projet. Les dépôts Git du projet (par exemple Choralys) sont indépendants de ceux de SymSvel. La CLI ne doit ni modifier les remotes/branches ni effectuer de `git merge`, `git reset` ou `git checkout` dans les composants du projet.

## Référence de provenance

Un manifeste dans `backend/.symsvel/components.json` conserve, par composant, la révision SymSvel installée et les empreintes des fichiers natifs. La récupération de la nouvelle version officielle se fait dans un répertoire temporaire, jamais dans le dépôt applicatif.

## Comparaison à trois états

Comparer la référence native installée (B), la version native disponible (N) et le fichier courant du projet (L) :

- Nouveau fichier N absent de B et de L : ajouter.
- Fichier natif changé dans N, identique à B dans L : remplacer.
- Fichier natif inchangé dans N, modifié dans L : conserver.
- Fichier modifié dans N et L : conflit, sans écrasement.
- Nouveau fichier N dont le chemin existe déjà dans L : conflit.
- Nouvelle route SvelteKit dont l'URL est déjà déclarée localement : conflit, même si le chemin est différent (groupes de routes, paramètres dynamiques, etc.).
- Fichier supprimé de N et inchangé localement : supprimer.
- Fichier supprimé de N mais modifié localement : conflit.

## Transaction

Pré-analyser tous les conflits avant mutation. En cas de conflit, refuser la mise à jour entière et fournir un rapport. En cas d'échec pendant l'application, restaurer les fichiers et le manifeste antérieurs, ainsi que les artefacts npm/build. N'écrire la nouvelle référence qu'après réussite des étapes de synchronisation et build.

## Compatibilité

Pour une installation historique sans manifeste, la CLI vérifie que `origin` pointe encore vers le dépôt officiel SymSvel, puis récupère en lecture seule l'ancêtre commun entre `HEAD` et la référence distante locale. Les fichiers natifs de cet ancêtre deviennent la référence de départ. Aucune branche ni aucun fichier Git du projet n'est modifié. Si la provenance est inconnue (par exemple dépôt Choralys déjà indépendant), la migration automatique est refusée : il faut initialiser une référence native fiable, sans assimiler arbitrairement les fichiers du projet à ceux du framework.

> Statut : moteur natif implémenté et testé dans Tooling (préflight, application, rollback). Le raccordement des parcours publics de la CLI doit être vérifié séparément : `symsvel update bo|app|front` appelle `symsvel:component:update`, tandis que `symsvel update` sans cible ou avec package utilise le lanceur transactionnel Composer et ne passe pas par `WorkspaceUpdater`. Ne pas présenter ces deux parcours comme équivalents.


## Initialisation documentaire (décision du 06/11/2026)

La création initiale du workspace prépare uniquement `dev-doc/docs`, `dev-doc/mkdocs.yml`, une page d'accueil minimale et `secrets/.env.example`. Elle **n'installe pas MkDocs**, ne crée pas d'environnement virtuel Python et ne lance aucun build documentaire. L'assemblage et la génération des documentations sont un chantier distinct (symsvel-back #29). Cette simplification n'affecte pas l'obligation de maintenir la documentation technique lors de toute modification du code.

## Guide mainteneur : mutualisation et frontières transactionnelles

**Principe SymSvel : une responsabilité, une implémentation de référence.** Lorsqu'une opération est utilisée par plusieurs parcours (CLI, commande Symfony, installation, mise à jour), ceux-ci doivent appeler le même service ou une méthode commune, plutôt que maintenir deux variantes presque identiques. Avant toute extraction, rechercher les services et tests existants ; éviter les abstractions créées pour des besoins hypothétiques.

### Responsabilités existantes à conserver

- `ComponentUpdater` : mise à jour native d'un composant Svelte ; utilise `ComponentNativeSource` pour lire la source officielle hors du Git applicatif, `ComponentUpdatePreflight` pour analyser les conflits et `ComponentManifest` pour suivre la provenance. Il possède aujourd'hui sa propre transaction et son rollback.
- `SymSvelComponentUpdateCommand` : point d'entrée qui délègue à `ComponentUpdater` (ou à l'installateur si le composant est absent).
- `WorkspaceUpdater` : orchestration de la mise à jour globale, avec Composer, sauvegardes des fichiers et artefacts, sauvegarde BDD et restauration en cas d'échec. **Son ancien parcours Git pour les interfaces est encore à remplacer** ; il ne doit pas devenir un second moteur de mise à jour native.

### Point de vigilance : transactions imbriquées

Réutiliser `ComponentUpdater::update()` tel quel depuis `WorkspaceUpdater` ne suffit pas à garantir l'atomicité de la mise à jour globale : un composant peut avoir validé son manifeste et nettoyé son checkpoint avant qu'un autre composant ou une étape globale échoue. Inversement, restaurer uniquement les fichiers ne restaure pas nécessairement le manifeste de provenance. La future orchestration devra donc définir explicitement **qui possède le commit, le rollback et le nettoyage des checkpoints**, sans dupliquer l'algorithme de comparaison ou d'application native.

### Critères de revue avant validation du chantier #60

1. Les mises à jour unitaires et globales réutilisent la même implémentation native ; aucune commande ne fait de `git merge`, `git checkout` ou `git reset` dans le dépôt applicatif.
2. Les modifications locales restent intactes et les conflits sont détectés avant mutation.
3. En cas d'échec global, fichiers, manifestes, dépendances et autres états concernés sont restaurés de manière cohérente ; les sauvegardes sont conservées si le rollback est incomplet.
4. Les tests couvrent les parcours unitaires et globaux, les conflits, les échecs intermédiaires et la récupération ; la CI est verte et les crash-tests sont effectués lorsque le nouveau processus d'installation est prêt.

> Cette section décrit les responsabilités vérifiées et le contrat de refonte, **pas une intégration déjà achevée**. La PR #62 reste en draft tant que ces critères ne sont pas satisfaits.

### Protection des checkpoints (lot de durcissement)

Avant toute récupération amont ou écriture de checkpoint, `ComponentUpdater` refuse désormais un répertoire `.symsvel` ou `.symsvel/transactions` qui serait un lien symbolique ou un fichier. Cela évite qu'un chemin de transaction redirigé entraîne des écritures hors du backend. Le test de régression vérifie qu'un dossier extérieur et les fichiers locaux restent intacts. Cette protection ne remplace pas le futur chantier de transaction globale ciblée.

### Validation des chemins de provenance (06/11/2026)

Le manifeste `backend/.symsvel/components.json` est une entrée persistante, pas une liste de chemins à considérer comme sûre par défaut. À la lecture **et** avant l'écriture, chaque empreinte doit avoir un chemin relatif au composant, sans segment `.` ou `..`, sans segment vide, sans séparateur Windows `\\` et sans octet NUL. Les empreintes restent des SHA-256 hexadécimaux. Un manifeste malformé est refusé avant le calcul du plan de mise à jour ou l'application des suppressions : aucune entrée ne doit pouvoir désigner un fichier extérieur à `bo/`, `app/` ou `front/`. Des tests de régression couvrent les chemins hostiles et la conservation du manifeste précédent en cas de refus d'écriture.

### Répertoire du manifeste : frontière de confiance

La vérification des chemins internes ne suffit pas si `backend/.symsvel` est lui-même redirigé. La lecture et l'écriture de `components.json` refusent donc un parent symbolique ou un fichier ordinaire. Cette vérification intervient avant de lire un manifeste existant, même lorsqu'aucune mise à jour n'a encore été appliquée. Les tests couvrent le refus en lecture et en écriture ainsi que la préservation du manifeste situé hors du backend.

### Périmètre des snapshots de sources (06/11/2026)

`WorkspaceFileSnapshot` accepte désormais une sélection explicite de composants (`backend`, `bo`, `app`, `front`) ; sans sélection, son comportement historique reste la sauvegarde du workspace entier. Les noms de composants non reconnus sont refusés. Le test de rollback ciblé vérifie que les fichiers des composants non sélectionnés ne sont ni restaurés ni supprimés.

**Attention à la synchronisation des modules :** `SvelteModuleSynchronizer::sync()` peut modifier les trois interfaces **et** le registre `backend/.symsvel/svelte-files.json`, même lorsque la mise à jour native porte sur un seul composant. En conséquence, `ComponentUpdater` conserve pour le moment un snapshot de sources global. Réduire son périmètre au seul composant provoquerait un rollback incomplet. Le mode ciblé est une primitive disponible pour les transactions dont les effets sont effectivement bornés ; son emploi dans l'orchestrateur global nécessite d'abord d'identifier tous les scopes touchés et leurs registres.

### Vérification avant suppression native (06/11/2026)

Le plan de comparaison est établi avant mutation, mais un fichier local peut changer entre le préflight et l'application. Juste avant chaque suppression native, `ComponentUpdater` vérifie de nouveau que le parent est dans le composant sans lien symbolique, que la cible est un fichier ordinaire non symbolique et que son SHA-256 correspond à l'empreinte native de référence. Si l'une de ces conditions échoue, la transaction est annulée et le rollback est déclenché, plutôt que supprimer un fichier local modifié entre-temps.

### Régression de rollback après suppression (06/11/2026)

Un test d'intégration utilise un dépôt Git amont temporaire pour publier la suppression d'un fichier natif. L'installation npm échoue volontairement après l'application du plan. Le test exige alors le rétablissement exact du fichier supprimé, de la référence de provenance et du HEAD Git applicatif, ainsi que le nettoyage du checkpoint. Ce scénario complète les contrôles de suppression juste avant `unlink` ; il ne vaut pas validation du rollback multi-composants, encore à intégrer dans `WorkspaceUpdater`.

### Nettoyage après rollback natif (06/11/2026)

En cas d'échec de `npm ci`, de synchronisation ou de build après application de fichiers natifs, `ComponentUpdater` restaure d'abord les sources et les artefacts runtime. Si toutes les restaurations réussissent, le checkpoint `.symsvel/transactions/update-*` est enregistré puis supprimé par `WorkspaceCleanupRegistry` avant de signaler l'échec initial. Si une restauration échoue, **le checkpoint est conservé** pour récupération manuelle. Si seule sa suppression échoue après restauration complète, l'erreur signale explicitement son chemin. Le test `testUpstreamDeletionRestoresNativeFileWhenNpmFails` vérifie la restauration du fichier supprimé, du manifeste et l'absence de checkpoint résiduel.

### Moteur d'application natif partagé (06/11/2026)

La mutation des fichiers du plan `add/replace/delete` est isolée dans `ComponentNativeFileApplier::apply()`. `ComponentUpdater` lui délègue désormais l'application après le préflight et avant npm, synchronisation, build et mise à jour du manifeste. L'applier ne lance **aucune** commande Git, ne crée aucun checkpoint et ne décide ni commit ni rollback : ces responsabilités restent à l'orchestrateur. Cela permet à la future mise à jour globale de réutiliser exactement les règles de copie, suppression, vérification des parents et revalidation SHA-256, sans créer un second algorithme. Les tests unitaires couvrent ajout/remplacement/suppression, conservation d'un fichier local non géré, refus d'une suppression après modification locale et refus d'un parent symbolique. **L'intégration dans `WorkspaceUpdater` et sa transaction globale restent à réaliser** avant validation de la PR.

### Fiabilité de la préparation PostgreSQL en CI (06/11/2026)

Le workflow `.github/workflows/ci.yml` utilise PostgreSQL 17 en service et installe `postgresql-client-17` via le dépôt PGDG sur Ubuntu 24.04. Le téléchargement de la clé publique PGDG ne doit jamais passer par un pipeline `curl | tee` : une interruption réseau peut créer une clé vide ou partielle tout en laissant le shell poursuivre vers APT. Le workflow télécharge désormais dans un fichier temporaire avec nouvelles tentatives, vérifie la structure de la clé avec `gpg --show-keys`, puis l'installe seulement si ces étapes réussissent. Les vérifications GPG d'APT restent actives. Ce changement cible la robustesse du provisioning CI, sans modifier le code métier ni la version PostgreSQL testée.

### Préparation native mutualisée (06/11/2026)

`ComponentNativePreparation::prepare()` regroupe la lecture de la provenance (`ComponentManifest`, avec récupération historique via `LegacyComponentBaseline`), le clonage officiel temporaire (`ComponentNativeSource`), l'inventaire des empreintes et le contrôle des conflits (`ComponentUpdatePreflight`). Il retourne la révision, les fichiers amont, la baseline et le plan sans appliquer de mutation au projet. `ComponentUpdater` utilise ce service dans son checkpoint existant et confie désormais la lecture de provenance à la préparation mutualisée, après création du checkpoint temporaire et avant toute mutation du projet. Le futur `WorkspaceUpdater` doit appeler cette même préparation pour **tous** les composants présents avant Composer, puis déléguer les mutations à `ComponentNativeFileApplier` dans sa propre transaction globale. Les tests d'intégration existants de `ComponentUpdaterTest` restent les tests de non-régression du parcours unitaire ; le parcours global nécessite des tests dédiés avant merge.

### Non-régression de la préparation partagée (06/11/2026)

`tests/Update/ComponentNativePreparationTest.php` vérifie, avec un dépôt Git amont temporaire local, que la préparation renvoie la révision et un plan sans modifier les fichiers projet ni `.symsvel/components.json`. Il vérifie aussi qu'une modification concurrente d'un fichier natif est refusée avant application. Ces tests ne remplacent pas les scénarios de rollback transactionnel global de `WorkspaceUpdater`, qui restent nécessaires avant clôture de la PR.

### Correction des avertissements CI (06/11/2026)

L'inspection des journaux PHPUnit (260 tests, 4 avertissements) a identifié une régression : après mutualisation de la préparation native, `ComponentUpdater` utilisait encore la variable supprimée `$baseline` au moment d'appliquer les fichiers. Le correctif transmet explicitement `prepared['baseline']` à l'appliqueur. Le test de commande supprime désormais toute son arborescence temporaire, y compris les checkpoints `.symsvel` produits par le préflight, afin d'éviter les avertissements `rmdir(): Directory not empty`. Les avertissements externes liés à `actions/checkout@v4` (Node 20) et à `initdb` sont distincts. Validation CI requise avant de considérer cette correction terminée.

Le workflow CI utilise désormais `actions/checkout@v5`, compatible avec le runtime Node.js 24 des runners GitHub Actions, afin de supprimer l'avertissement de dépréciation Node.js 20. Le message `initdb` sur l'authentification locale du conteneur PostgreSQL de test ne provient pas du code applicatif.

La CI exécute désormais PHPUnit avec `--display-warnings --fail-on-warning` : un avertissement PHP émis par les tests rend le job rouge, même si toutes les assertions passent. Ce garde-fou empêche la répétition du faux positif constaté sur `ComponentUpdater`. Le résultat reste soumis à la CI et aux crash-tests XT avant validation du chantier.

### Protection contre les modifications concurrentes (07/11/2026)

Entre le préflight trois voies et l'application, un fichier local peut être créé ou modifié. `ComponentNativeFileApplier` vérifie désormais juste avant écriture que les chemins `add` n'existent toujours pas et que les fichiers `replace` correspondent encore à leur empreinte de baseline ; la vérification équivalente existait déjà pour `delete`. En cas d'écart, l'opération échoue sans écraser la modification locale et le propriétaire de la transaction assure le rollback. Deux tests de non-régression couvrent ces changements concurrents. Ce contrôle ne remplace pas un verrou transactionnel de workspace et ne garantit pas l'atomicité entre la vérification et la copie ; cette limite reste à examiner lors de l'orchestration globale #60.

### Préparation native de l'ensemble du workspace (07/11/2026)

`WorkspaceNativePreparation` réutilise `ComponentNativePreparation` pour préparer successivement les interfaces installées (`bo`, `app`, `front`) dans un checkpoint appartenant à l'appelant. Les interfaces absentes sont ignorées ; les liens symboliques au niveau d'un composant sont refusés. Aucun fichier du projet ni manifeste n'est modifié pendant cette phase. Si le dernier composant présente un conflit, les composants précédents restent intacts. Les tests couvrent ce comportement ainsi que la préparation des composants présents. **État au moment de cette étape (historique) :** `WorkspaceUpdater` utilisait encore l'ancien parcours Git. Le raccordement natif a ensuite été réalisé ; voir « Premier raccordement effectif de WorkspaceUpdater » plus bas.

### Application native globale préparée (07/11/2026)

`WorkspaceNativeApply` applique les plans prévalidés des interfaces présentes, sans exécuter Composer, npm ni créer de checkpoint. Il refuse les conflits résiduels avant la première mutation, utilise `ComponentNativeFileApplier` pour chaque interface, puis écrit les révisions et empreintes dans `ComponentManifest` seulement après l'application de tous les fichiers. Un échec pendant l'application ou l'écriture des manifestes doit être pris en charge par la transaction englobante, qui restaure sources et manifeste. Tests : plusieurs interfaces, conservation des fichiers personnalisés, refus global d'un plan conflictuel. **État au moment de cette étape (historique) :** le raccordement à `WorkspaceUpdater` restait à réaliser. Il est désormais présent dans le parcours actif ; sa validation transactionnelle complète reste distincte.

### Contrôle global juste avant application (07/11/2026)

`WorkspaceNativeApply` revérifie les cibles `add`, `replace` et `delete` de **tous** les composants avant d'écrire le premier fichier. Une modification locale tardive dans App bloque ainsi toute mutation préalable dans BO. Les contrôles par fichier de `ComponentNativeFileApplier` restent actifs pendant l'application ; cette double vérification réduit les risques de modifications concurrentes, sans fournir à elle seule de verrou atomique. Test de non-régression : conflit tardif sur le second composant, premier composant intact, manifeste non modifié. **État au moment de cette étape (historique) :** l'intégration à `WorkspaceUpdater` restait à effectuer ; le raccordement natif a depuis été ajouté.

### Indépendance du Git projet (07/11/2026)

Un test de `WorkspaceNativeApply` vérifie qu'une mise à jour d'un fichier natif ne modifie ni `.git/HEAD` (branche locale du projet) ni un fichier personnalisé non géré. Cette garantie concerne la phase d'application native uniquement : l'ancien parcours de `WorkspaceUpdater` appelait encore `git fetch`, `git merge` ou `git checkout`. Ces appels ont depuis été retirés du parcours actif, sans que cela dispense de valider le rollback global. Cette distinction est obligatoire dans les validations et les communications du chantier #60.

### Validation des chemins dans les plans globaux (07/11/2026)

Avant toute écriture, `WorkspaceNativeApply` rejette les chemins absolus, les segments `.` ou `..`, les segments vides et les séparateurs inverses dans les opérations `add`, `replace` et `delete`. Le contrôle s'applique à l'ensemble des composants : un chemin invalide dans App bloque l'application préalable des fichiers BO. Un test vérifie le rejet et l'absence de mutation des sources et du manifeste. Cette validation complète les contrôles de chemins et de symlinks de l'appliqueur unitaire.

### Test du rollback sources et manifeste (07/11/2026)

`WorkspaceNativeRollbackTest` combine les composants BO et App avec le snapshot existant `WorkspaceFileSnapshot` (périmètre `backend`, `bo`, `app`). Après application native et publication des nouvelles révisions, une erreur simulée de build déclenche la restauration des fichiers natifs, des personnalisations locales et du manifeste `.symsvel/components.json` à leurs états antérieurs. Il s'agit d'un test de composition des briques existantes, **pas** encore d'un test du parcours complet de `WorkspaceUpdater` : le raccordement de la transaction Composer, npm et BDD reste nécessaire.

### Échec réel pendant l'application de plusieurs composants (07/11/2026)

Le test `WorkspaceNativeRollbackTest::testPartialApplyFailureRestoresEarlierComponentAndOriginalManifest` prépare BO et App, applique BO, puis provoque un échec d'application App par absence du fichier source upstream. Le snapshot des sources restaure alors le fichier BO et le manifeste initial, tandis que App reste inchangé. Cette vérification démontre la récupération d'une application partielle ; elle ne remplace pas les tests d'intégration du rollback complet dans `WorkspaceUpdater` (Composer, runtime, BDD).

### Disparition d'une source upstream entre préflight et copie (07/11/2026)

`ComponentNativeFileApplier` vérifie désormais l'existence et le type régulier du fichier upstream juste avant la copie. Si la source a disparu ou est devenue un lien symbolique, l'opération échoue avec une `RuntimeException` explicite, sans warning PHP. Les erreurs de copie et de permissions sont également converties en exceptions contrôlées ; la transaction appelante doit restaurer les fichiers déjà modifiés. Le crash-test `WorkspaceNativeRollbackTest` vérifie le message d'erreur attendu et la restauration du premier composant après échec du second.

### Contrôle global des sources upstream (07/11/2026)

`WorkspaceNativeApply` vérifie désormais, pour **tous** les composants, l'existence, l'absence de symlink et le SHA-256 attendu des fichiers upstream destinés aux ajouts et remplacements **avant toute mutation**. Une source App absente ou altérée bloque donc aussi les écritures BO. Le test de disparition de la source App a été adapté à cette garantie renforcée ; la vérification locale de `ComponentNativeFileApplier` reste utile contre les changements entre le contrôle global et la copie effective. Les scénarios de rollback après mutation restent couverts séparément par `testSourceSnapshotRestoresBothInterfacesAndManifestAfterDownstreamFailure`.

### Chemins parents upstream symboliques (07/11/2026)

Le préflight global rejette aussi les répertoires parents symboliques des fichiers upstream, même si le fichier terminal est régulier et que son empreinte SHA-256 correspond. Un test place un parent upstream App en symlink vers un dossier extérieur et vérifie que l'application BO n'a pas commencé. La vérification porte sur tous les composants avant écriture ; les contrôles locaux restent une défense supplémentaire contre les changements concurrents.

## Raccordement transactionnel à WorkspaceUpdater : contrat d'intégration

**Historique de conception (avant raccordement du 07/11/2026) :** le parcours Git de `WorkspaceUpdater` était encore actif. Le plan ci-dessous documente le chantier réalisé ensuite et ses critères de sortie, pas des instructions à réimplémenter.

1. **Préflight global avant Composer** : après validation du backend, de la configuration BDD et de `composer.lock`, créer un checkpoint transactionnel privé ; appeler `WorkspaceNativePreparation::prepare($backend, $checkpoint)` pour tous les composants installés. Toute collision interrompt l'opération sans modifier Composer, les interfaces ou le manifeste.
2. **Snapshots avant mutation** : conserver les sauvegardes Composer, vendor, runtime npm/build et BDD existantes ; inclure explicitement les sources des composants et `.symsvel/components.json` dans `WorkspaceFileSnapshot`. Les clones upstream sont conservés dans le checkpoint jusqu'à la fin de la transaction.
3. **Exécution** : lancer Composer et publier ses hooks ; appeler une seule fois `WorkspaceNativeApply::apply($backend, $prepared)`, qui revérifie tous les composants puis applique les fichiers et le manifeste ; exécuter `npm ci` et `npm run build` uniquement sur les composants présents.
4. **Échec** : restaurer indépendamment runtime npm/build, manifests Composer, vendor, sources backend et interfaces (y compris manifeste de provenance), puis BDD. Agréger les erreurs de rollback et conserver les checkpoints si la récupération n'est pas garantie.
5. **Succès** : enregistrer le nettoyage différé des checkpoints via `WorkspaceCleanupRegistry`. Ne jamais traiter un échec de nettoyage comme un échec de la mise à jour déjà validée.
6. **Suppression de l'ancien mécanisme** : retirer du parcours et des helpers de `WorkspaceUpdater` les vérifications `.git`/dirty status, `GitComponentCheckpoint`, `git fetch`, `git merge`, `git checkout` et la sélection locale des tags. Le dépôt Git du projet n'est jamais modifié par la mise à jour native.

### Tests de sortie indispensables

Les tests historiques de `WorkspaceUpdaterTest` et `WorkspaceUpdaterGitRollbackTest` doivent être remplacés ou adaptés avec des dépôts upstream locaux et de vrais fichiers, sans branche de production spéciale pour les runners de tests. Vérifier : projet sans `.git` ; modifications locales conservées ; conflit dans le dernier composant avant Composer ; échec Composer ; échec de build du dernier composant après application native ; rollback des sources et de la provenance ; rollback vendor, runtime et BDD ; conservation des checkpoints si un rollback échoue ; absence de warning PHP. La CI verte seule ne clôture pas le chantier : crash-tests XT et documentation à jour restent obligatoires.

### Premier raccordement effectif de WorkspaceUpdater (07/11/2026)

Le parcours `WorkspaceUpdater::update()` appelle désormais `WorkspaceNativePreparation` avant Composer, puis `WorkspaceNativeApply` après la mise à jour Composer et la publication des hooks, avant les commandes npm. Les anciens appels de vérification du Git projet, `git fetch`, `git merge`, `git checkout` et la restauration par `GitComponentCheckpoint` ont été retirés du parcours actif. Le checkpoint des clones upstream est enregistré dans le nettoyage différé à la réussite. **Chantier non terminé** : les tests historiques de `WorkspaceUpdaterTest` et `WorkspaceUpdaterGitRollbackTest` restent à migrer ; la gestion des checkpoints lors d'un échec de préflight et la vérification de la restauration transactionnelle complète doivent être éprouvées avant toute clôture. Les helpers Git historiques non utilisés restent à supprimer après migration des tests.

### Migration des tests historiques (07/11/2026)

`WorkspaceUpdater` accepte maintenant une dépendance facultative `WorkspaceNativePreparation` en fin de constructeur. En production, le préflight réel reste utilisé par défaut ; les tests d'intégration peuvent fournir un `ComponentNativePreparation` construit avec un `ComponentNativeSource` pointant vers des dépôts Git upstream **locaux**. Il ne s'agit pas d'un contournement réservé aux tests : la préparation et les vérifications natives sont identiques. Les anciennes fixtures sans remote `origin` et les assertions de `git merge` doivent être remplacées. Tant que cette migration n'est pas terminée, la CI du raccordement global n'est pas validée.

### Fixtures d'intégration natives (07/11/2026)

`tests/Update/NativeWorkspaceFixture.php` prépare des dépôts Git upstream locaux et des entrées de provenance `.symsvel/components.json` pour chaque composant. Le préflight injecté continue d'exécuter le code réel `ComponentNativeSource` / `ComponentNativePreparation` / `WorkspaceNativePreparation`. Les scénarios de rollback historiques restent à migrer vers cette fixture ; leur état rouge est attendu tant qu'ils exigent encore les commandes Git projet supprimées.

### Migration effective d'un test WorkspaceUpdater (07/11/2026)

Le scénario `testBuildFailureIdentifiesComponentAndStage` utilise maintenant `NativeWorkspaceFixture`, un upstream Git local et un manifeste natif initial, et vérifie que l'échec du build BO est bien atteint après l'application native. Son nettoyage récursif prend en compte les checkpoints de transaction. Les autres scénarios historiques n'ont pas encore été migrés ; aucun résultat de CI n'est anticipé.

### Migration des scénarios WorkspaceUpdaterTest (07/11/2026)

Les scénarios de succès BO, de refus de contrainte Composer instable en mode dev, de conflit natif bloquant avant Composer et de conservation d'un fichier local non suivi utilisent désormais le contrat natif. Les assertions sur `git status`, `git merge` et `git checkout` ont été remplacées par des contrôles des commandes Composer/npm et du contenu réel des fichiers. Le scénario d'échec de build BO était déjà migré. **Les cinq scénarios historiques de `WorkspaceUpdaterGitRollbackTest` restent à migrer et la CI n'est pas encore confirmée**.

### Migration des cinq derniers scénarios de rollback (07/11/2026)

Les tests d'échec de build FRONT, de restauration des artefacts npm/build, de rollback complet SQLite, de rollback PostgreSQL et de sauvegarde BDD corrompue ont été raccordés aux fixtures natives et à `WorkspaceNativePreparation` injectée. Les assertions de restauration Git projet et les simulations `git merge` ont été retirées de ces scénarios, au profit des vérifications de fichiers natifs, de snapshots et de BDD. Le scénario autrefois intitulé « échec de récupération Git FRONT » vérifie maintenant la restauration des artefacts runtime FRONT, puisque le mécanisme Git de restauration n'existe plus. **Résultat CI à vérifier avant validation ; les cas de défaillance de restauration native devront encore faire l'objet de tests spécifiques si absents.**

### Environnements runtime (07/11/2026)

`WorkspaceUpdater` accepte `APP_ENV=dev` et `APP_ENV=test` et refuse `prod` pour les mises à jour ; l'ancien `APP_ENV=core` est désormais explicitement rejeté. Le mode `--dev` de la CLI est un **choix de provenance des packages** (derniers commits de `dev-main`), et ne doit pas être confondu avec `APP_ENV=dev` de Symfony. Les scénarios d'intégration transactionnels PHPUnit utilisent `test` ; un test vérifie le rejet de `core` avant toute commande. Les contraintes Composer stables sont contrôlées dans le parcours runtime `dev` existant ; leur cohérence avec le parcours CLI `--dev` doit être vérifiée lors de la coordination CLI #9. La validation complète reste soumise à la CI et à la revue des dépendances.

### Politique de provenance persistante (07/11/2026)

Le projet conserve son canal dans `backend/.symsvel/project.json` : `{"schema":1,"channel":"stable"}` par défaut ou `{"schema":1,"channel":"dev"}` lors de `create-project --dev` (mainteneurs et crash-tests uniquement). Le canal est indépendant de `APP_ENV` et ne change pas implicitement. La classe `ProjectChannel` assure la lecture stricte et l'écriture initiale sans écrasement ; les tests couvrent les configurations manquantes, invalides et symboliques. **État historique de ce jalon :** l'écriture depuis CLI create-project et la lecture par les différents parcours restaient à raccorder. Les implémentations ont évolué dans les PR CLI #10 et Tooling #62 ; vérifier le code et les CT avant de conclure à la validation de bout en bout. Aucun mécanisme de migration d'anciens projets ni de changement de canal n'est prévu.

#### Résolution des sources Svelte

La préparation native lit désormais `ProjectChannel` depuis le backend. Pour `dev`, le clone de référence cible explicitement `main` ; pour `stable`, le résolveur choisit le tag SemVer stable le plus récent (`vMAJOR.MINOR.PATCH` ou `MAJOR.MINOR.PATCH`) depuis le dépôt officiel. Les tests d'intégration créent un canal `dev` et des upstream Git locaux. **État historique :** la configuration initiale CLI et le parcours Composer restaient à raccorder à cette étape. Se reporter aux PR actuelles et à leurs tests pour l'état effectif.

#### Fixtures Git de CI

Les tests d'intégration des mises à jour natives créent explicitement leurs dépôts Git locaux sur `main` (source et dépôt bare). Le résolveur `dev` cible cette branche par contrat ; les tests ne dépendent plus de la branche initiale configurée sur le runner GitHub Actions. La création du répertoire `.symsvel` est assurée par `ProjectChannel`, sans double `mkdir` dans les fixtures.

### Nettoyage des vestiges Git de WorkspaceUpdater (08/11/2026)

La revue pré-merge a confirmé que `onlyManagedGeneratedFiles()`, `latestReleaseTag()` et `output()` étaient des méthodes privées sans appel dans `WorkspaceUpdater`. Elles ont été supprimées, sans modifier le parcours actif de mise à jour native. `run()` et son exécution de Composer et npm sont conservés. Le code actif continue de préparer et appliquer les sources natives sans fusion ni checkout Git des dépôts du projet. La CI du commit de nettoyage et les crash-tests XT restent des validations distinctes.

### Revue globale pré-merge : suppression des protections Git historiques (08/11/2026)

`GitComponentCheckpoint` et son test ont été retirés : ils restauraient un composant par `git checkout -f` et `git reset --hard`, incompatibles avec la mise à jour native et sans appel dans les parcours actifs examinés. `GeneratedSvelteStatusGuard` et son test ont également été retirés : ils imposaient l'ancien contrôle de `git status` avant une mise à jour. La sécurité des mises à jour natives relève désormais de `ComponentUpdatePreflight`, `ComponentFilePlanner`, `ComponentRouteCollisionGuard`, `ComponentNativeFileApplier` et des snapshots de sources, avec leurs tests dédiés. `LegacyComponentBaseline` reste volontairement présent : il lit l'historique Git d'un ancien composant pour établir une provenance initiale vérifiable, sans modifier HEAD, branche ni fichiers. Les tests de rollback natif et de conservation des modifications locales restent la référence ; la validation des derniers commits nécessite une CI verte et les CT XT après fusion.


## Incident d'assemblage BO XT du 16/11/2026 : dépendance UI entre dépôts

Les pages BO des modules Admins, Users et Settings utilisent désormais
`Button`, `Alert` et `DataTable` appartenant au dépôt `symsvel-bo`.
Leurs imports relatifs depuis
`src/lib/modules/<module>/routes/<module>/+page.svelte` montent bien
de quatre niveaux jusqu'à `src/lib/components/`.

Les composants génériques sont présents dans la branche `dev` du BO,
pas dans la branche `main` historique. En cas d'assemblage Vite échouant sur
`UNRESOLVED_IMPORT .../components/Button.svelte`, examiner l'état des fichiers
natifs réellement installés dans le projet, avant de modifier les imports :
un `symsvel update` Composer ne réalise **pas** de mise à jour native du BO.
La séquence de maintenance du canal `dev` est :

```bash
symsvel update bo
symsvel update
```

`symsvel update bo` sélectionne la branche correspondant au canal de
`backend/.symsvel/project.json`, applique les fichiers natifs avec sauvegarde,
synchronise les modules et effectue un build avant validation du manifeste.
En cas d'échec, son rollback peut laisser dans XT l'ancien BO sans les
composants nouveaux. Ne pas changer le canal à la main, injecter les
composants dans `vendor/`, ni contourner les contrôles de provenance.

Les CI d'intégration des modules assemblent un checkout BO `dev` neuf ;
elles ne remplacent pas le crash-test des mises à jour ordonnées sur un
workspace existant. L'incident ne permet pas, à lui seul, de conclure à un
défaut des chemins d'import ni à un bug du planificateur trois voies.


## Correctif XT : snapshot runtime sur le même volume (16/11/2026)

Le CT `symsvel update bo` a rencontré
`Snapshot runtime incohérent : node_modules` **avant** l'application des
nouveaux fichiers BO. L'ancien `RuntimeArtifactSnapshot` copiait la totalité
des dépendances npm et comparait ensuite la copie à l'original. Ce contrôle
prouvait une divergence, sans en identifier la cause (fichier mutable,
métadonnées ou autre). Il serait dangereux de supprimer la vérification.

Pour les mises à jour **unitaires** `bo/app/front` via `ComponentUpdater`,
la capture utilise désormais `moveOnCapture: true` : les anciens répertoires
`node_modules`, `build`, `dist` et `.svelte-kit` sont **déplacés** dans
le checkpoint de la même partition avant `npm ci` et le build. Le déplacement
conserve les fichiers, permissions et liens symboliques sans copie d'un arbre
npm potentiellement mutable. Un volume différent est refusé avant toute
mutation. Le contenu déplacé reçoit une empreinte contrôlée avant tout
rollback, comme auparavant.

- En cas de réussite, l'ancien runtime est nettoyé avec le checkpoint.
- En cas d'échec de l'installation/build, le nouveau runtime est retiré et
  les anciens dossiers sont remis en place par déplacement, après contrôle
  d'intégrité de **tous** les dossiers sauvegardés.
- Si la capture échoue après un premier déplacement, elle tente de remettre
  immédiatement tous les dossiers déplacés. Si cette restauration échoue,
  `ComponentUpdater` **conserve le checkpoint** et signale son emplacement.
- Les snapshots des transactions Composer et du `WorkspaceUpdater`, y compris
  `vendor`, gardent leur fonctionnement par **copie**. Ils ne doivent pas
  perdre l'accès aux fichiers au moment de leur propre capture.

Tests de régression : restauration avec lien symbolique et inode conservé,
capture partielle, snapshot altéré et refus d'un artefact racine symbolique.
L'incident XT ne sera clôturé qu'après CI verte et crash-test réel sur XT.


## Snapshot runtime de la maintenance Composer (16/11/2026)

Le lanceur transactionnel de `symsvel update` (sans cible ou avec un
package) réutilise les dépendances npm pour les builds déclenchés par ses
hooks. Même si le parcours actuel n'exécute pas `npm ci`, les tests
de rollback garantissent le rétablissement exact du runtime **y compris
`node_modules`**, si un hook ou une commande l'a modifié.

Pour éviter de copier et contrôler un arbre npm **mutable** pendant sa
capture, `ComposerTransactionRunner` utilise le même déplacement sur
le volume que la mise à jour native, mais avec `keepWorkingCopy: true` :

1. Déplacer les originaux `node_modules`, `build`, `dist` et
   `.svelte-kit` dans le checkpoint sur la **même partition**.
2. Créer une copie de travail à partir du checkpoint désormais stable,
   et vérifier les empreintes avant Composer.
3. Garder les copies de travail disponibles pendant les hooks et builds.
4. Si la transaction échoue, vérifier les originaux sauvegardés **avant**
   de remplacer les copies modifiées par les dossiers déplacés.
5. Si elle réussit, conserver le runtime actif et nettoyer les originaux
   sauvegardés avec le checkpoint.

Une capture partielle tente de replacer les dossiers déjà déplacés ; une
restauration incomplète conserve le checkpoint pour récupération. Les
snapshots de `vendor` et du `WorkspaceUpdater` restent en mode copie ;
ce dernier réalise lui-même `npm ci` et reste un parcours distinct.

Régression : vérification des copies de travail accessibles aux hooks,
d'un original déplacé intact et du rollback des builds **et**
des modifications simulées dans `node_modules` pour BO/App/Front.
La clôture nécessite la CI verte et un crash-test XT.

## Mise à jour du squelette Back (24/11/2026)

`symsvel update back` est une cible native distincte des packages Composer et des interfaces. La CLI délègue au lanceur Tooling sans démarrer le Kernel. À la création, Composer conserve temporairement le checkout source officiel ; Tooling enregistre la révision exacte et les empreintes Git natives de Back dans `backend/.symsvel/components.json`, ainsi que le manifeste Composer natif de référence, puis la CLI retire ce Git upstream uniquement dans le répertoire fraîchement créé. Le recorder Tooling ne supprime jamais de Git. Les projets sans provenance fiable sont refusés, sans assimilation de leurs fichiers à une version officielle.

Le canal enregistré sélectionne la branche dev ou le dernier tag stable. Le préflight réutilise le planificateur natif à trois états, sans scanner vendor ni les fichiers projet non gérés. Les configurations privées, les métadonnées .symsvel, le Git applicatif, les artefacts runtime et les locks Composer/Flex ne sont jamais importés depuis le squelette. Le manifeste Composer est comparé à trois états par clé (listes atomiques) ; les contraintes et dépôts locaux sont conservés lorsqu'ils ne sont pas en conflit, notamment les contraintes dev-dev de Core/Tooling. Tout conflit bloque l'opération avant mutation.

La transaction Composer existante possède le snapshot sources/vendor/npm/BDD, applique le plan natif après capture, résout le lock du projet par `composer update --minimal-changes --no-interaction`, exécute les hooks, migrations et synchronisations existants, puis exige `composer audit --locked` avant publication de la nouvelle provenance. Les dépendances autorisées par les contraintes du projet peuvent évoluer. Un échec restaure fichiers, lock, artefacts et provenance ; aucun Git applicatif n'est modifié. Une même révision est un no-op. Tests automatisés : mise à jour réelle d'une source Git locale, préflight conflictuel, fusion Composer, rollback. CT local obligatoire avant release.

### Visibilité des builds unitaires

`symsvel update bo|app|front` relaie désormais la sortie des commandes npm et annonce leurs étapes et leur réussite. Le build existant reste obligatoire dans la transaction, après synchronisation des modules et avant validation de la provenance. Le test d'intégration exécute un vrai `npm ci` et un vrai `npm run build` sur une source Git locale avec un inventaire Composer valide (aucun module installé), puis vérifie le contenu du fichier construit et les messages. Un échec conserve le comportement de rollback existant.

### Frontière de propriété du fichier `.env`

Le `.env` versionné du squelette sert à l'initialisation, puis appartient au projet et à Symfony Flex, qui peut ajouter ou retirer les variables de recettes. `symsvel update back` ne le remplace ni ne le fusionne depuis le squelette ; `.env.example` reste une référence native mise à jour. Les nouvelles variables facultatives doivent être tolérées par les services runtime lorsqu'elles sont absentes, et les variables obligatoires sont documentées dans l'exemple. Les anciens manifestes de provenance contenant `.env` sont compatibles : seule cette entrée est retirée du plan natif, puis du manifeste à la réussite de la transaction. Aucune modification manuelle de provenance n'est requise. Les `.env.local` restent protégés. Les hooks Composer/Flex conservent leur rôle habituel ; les snapshots et le rollback restent inchangés. Les conflits des autres fichiers natifs restent bloquants. Un test reproduit une modification locale de `.env` et une évolution concurrente du squelette, vérifie la conservation du fichier projet et la mise à jour de `.env.example`.

### Contraintes resserrées par Composer

Le `bump-after-update` du squelette peut faire évoluer localement les contraintes sans personnalisation volontaire. Dans `require` et `require-dev`, les conflits de chaînes numériques de versions de packages sont comparés via la bibliothèque officielle `composer/semver` (dépendance explicite de Tooling). Si les deux contraintes sont incluses dans celle de référence et que l'une est incluse dans l'autre, la fusion conserve la plus restrictive (la locale si équivalente). Exemple : `^2.12|^3.30` → `^3.31.1` côté Back et `^3.30.0` côté projet donnent `^3.31.1`. Une contrainte locale plus restrictive est conservée. Les intersections partielles, divergences, élargissements simultanés, branches, alias et indicateurs de stabilité restent des conflits à résoudre explicitement. La résolution Composer et l'audit du lock restent obligatoires ; aucun contournement de la sécurité ou du préflight. Tests de régression : plancher Twig, contrainte locale stricte, contraintes incompatibles et ambiguës.
