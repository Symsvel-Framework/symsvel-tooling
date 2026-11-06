# Contrat de mise à jour des composants Svelte (V1)

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

> Statut : contrat de conception. L'implémentation et les tests de migration sont à réaliser dans cette PR avant tout merge.


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
