# Contrat et intégration des modules

Le contrat du module définit la structure, les scopes, les ressources et les conventions nécessaires à l'intégration par le Tooling.

- **Back** : bundle Symfony, contrôleurs, services, entités et migrations propres au module.
- **BO / App / Front** : pages, composants et tests correspondant aux scopes déclarés.
- **Documentation** : configuration, API et fonctionnalités du module pour chaque interface concernée.

Les modules sont développés dans leur dépôt ou leur workspace. `vendor/` est une destination d'installation Composer, pas un emplacement de développement.

## Ressources gérées

Les recettes Flex apportent les fichiers d'intégration Symfony. Le Tooling synchronise les ressources Svelte et enregistre des empreintes des fichiers générés. Il refuse d'écraser les fichiers non gérés et les fichiers gérés modifiés localement.

Lors d'une désinstallation, seules les ressources attribuables au module peuvent être retirées ; les fichiers locaux doivent être préservés. Les dépendances entre modules sont contrôlées avant une cascade de suppression.

Voir [les commandes](commandes.md).

## Arborescence Svelte

Les fichiers `bo|app|front/src/lib/**` d'un module sont reproduits **à l'identique** dans `src/lib/**` de l'interface cible, sans préfixe artificiel `vendor/<slug>`. Le module est responsable de son espace de noms (par exemple `lib/modules/admins/index.ts`). Les collisions avec les fichiers locaux ou d'autres modules sont refusées, sans écrasement.

Les sources `src/routes/**` du module sont conservées sous `src/lib/modules/<slug>/routes/**` pour éviter les collisions entre fichiers sources et routes SvelteKit. Un fichier `+page.svelte` génère une page relais sous `src/routes/**` qui importe la source correspondante. Un module ne déclarant que `index.ts` ne génère aucune page.

Le registre `.symsvel/svelte-files.json` protège les fichiers gérés. Une synchronisation après mise à jour migre les anciens chemins gérés `src/lib/vendor/<slug>/**` vers les nouvelles destinations, sous réserve d'absence de modification locale et de collision. Les fichiers locaux ne sont jamais supprimés.


## Scopes Svelte optionnels d'un module (17/11/2026)

Un module peut déclarer dans `module.json` les composants `bo`, `app` et `front` qu'il **sait fournir**. Cette déclaration ne signifie pas que le projet consommateur doit obligatoirement installer App ou Front. `SvelteModuleSynchronizer` synchronise uniquement les composants présents dans le workspace. Si App ou Front n'existe pas, leurs fichiers sont ignorés, sans créer les applications manquantes ni refuser un projet BO seul. Le BO, composant natif obligatoire, reste exigé lorsqu'un module déclare des fichiers BO. Si App est ajoutée ultérieurement, une nouvelle synchronisation installe les fichiers et routes correspondants.

Le correctif est motivé par le module optionnel `module-two-factor`, qui fournit une page BO et une page App sans rendre App obligatoire. Le comportement antérieur refusait les projets BO seuls dès qu'un module installé déclarait un scope App. Le test `SvelteModuleSynchronizerTest::testBoOnlyProjectSkipsOptionalAppAndFrontScopes` couvre explicitement cette régression. Les contraintes de dépendances fonctionnelles comme Users obligatoire **lorsqu'App est activée** restent décidées par la CLI et le manifeste du Back ; aucun composant n'est installé automatiquement.

## Guide pour les développeurs de modules BO

Avant de créer une page BO d'un module, réutiliser les composants natifs du BO et leurs jetons. Le Tooling synchronise les pages mais ne duplique pas les composants du hôte.

[Guide développeur BO : composants et couleurs](https://github.com/Symsvel-Framework/symsvel-bo/blob/dev/docs/dev/bo-components.md)

## Imports Symfony des modules Composer locaux (19/11/2026)

L'ADR Back 0003 conserve le routage **Symfony Flex**, avec un fichier
indépendant par module. Un dépôt local installé depuis `modules/<slug>` par
un repository Composer `path` avec symlink n'entraîne pas l'application
automatique de sa recette Flex. `ComposerModuleRouteSynchronizer` comble
strictement ce cas en lisant la déclaration `recipe/recipe.json` du module
installé. Une importation n'est ajoutée que si la recette déclare
explicitement `config/routes/<slug>.yaml`, le namespace attendu et
`type: attribute`, et que le module possède au moins un contrôleur PHP.
Aucun scan implicite de routes ni routeur propriétaire n'est introduit.

La route importée pointe vers l'entrée Composer
`../../vendor/symsvel-framework/module-<slug>/back/src/Controller/`, ce
qui reste valide pour les symlinks de développement. Les packages normaux,
distribués par Flex, conservent leur gestion de routes par Flex.
Un fichier déjà présent dans `config/routes/` et non répertorié comme
généré est présumé appartenir à Flex ou au projet : il n'est ni modifié,
ni adopté, ni supprimé. Le registre **de propriété**, et non de routage,
`backend/.symsvel/composer-routes.json` garde uniquement les empreintes
des fichiers générés pour détecter toute modification locale ou collision.
Les entrées hors périmètre, symboliques, altérées ou manquantes provoquent
un échec avant écriture. Sur `remove`, seules les routes portant une
empreinte gérée inchangée sont retirées.

Le hook Composer précache du Back exécute cette synchronisation **avant**
`cache:clear`, pour éviter que Symfony charge un import devenu caduc après
un retrait de module. La commande interne
`symsvel:module:sync --source=composer` la répète de manière idempotente.
Les checkpoints de la transaction Composer couvrent `config/routes` et
`.symsvel` ; un appel direct hors transaction ne possède pas cette garantie
de récupération. Le test automatisé couvre module avec/sans contrôleur,
symlink `path`, distribution classique, fichiers locaux/Flex,
idempotence, retrait et refus des registres ou fichiers altérés.

La validation du routage réel (`debug:router`, `router:match`, chemins
publics), des collisions entre différentes routes déclarées et du cycle
`require/update/remove` sur XT reste indispensable avant clôture de
l'issue #64.

La fixture d'intégration CLI/Svelte monte désormais également un répertoire de routes Symfony et un contrôleur de test explicite. Elle vérifie que la synchronisation Composer publie, conserve lors d'un second passage, puis retire ensemble les routes Symfony gérées et les relais Svelte lors de la suppression de l'inventaire du module. Les CT restent nécessaires pour vérifier le routeur réel.

## Assemblage de la documentation native (19/11/2026)

La commande de maintenance interne `symsvel:documentation:sync`, appelée
par `symsvel documentation:sync`, rassemble les fichiers Markdown
`.md` déclarés sous `module.json.documentation` pour les modules
**réellement installés par Composer**. Les sources `docs/` du Back et des
hôtes BO/App/Front présents rejoignent uniquement le scope `dev` ;
les documents `admin` et `user` appartiennent aux modules. Les
fichiers sont conservés par propriétaire et chemin relatif, sans
inventer de traduction ni copier des ressources hors manifeste.

Tooling publie un index JSON privé et déterministe dans
`backend/var/symsvel/documentation.json`. Il contient les titres,
scopes, identifiants et sources Markdown. Le fichier est hors `public/`
(et hors builds Svelte), avec checksum d'intégrité et publication par
renommage atomique. Une modification locale de cet artefact, un lien
symbolique dans les sources ou une source excessive bloque la
synchronisation. Le retrait d'un module en retire les pages lors de la
prochaine synchronisation. Aucun contenu privé n'est copié en
`bo/static` : le BO devra accéder à cet index par un endpoint BO
authentifié, détenu par un module natif, et ne jamais exposer directement
le fichier à HTTP.

Cette opération est **explicitement manuelle** après Setup. Elle ne
lance ni Python, ni `pip`, ni MkDocs, ni build Svelte pendant
`create-project`, le Wizard ou la compilation de modules. Elle ne
remplace pas une publication MkDocs : tout build MkDocs ultérieur sera
également une maintenance hors installation. Test d'inventaire, de
régénération, de retrait, de collision et de protection du document
requis en CI ; CT XT et accès authentifié BO restent indispensables.
