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
