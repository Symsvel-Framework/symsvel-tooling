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
