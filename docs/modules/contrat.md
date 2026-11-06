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
