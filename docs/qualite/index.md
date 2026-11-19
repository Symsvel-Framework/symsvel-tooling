# Qualité et contribution

Une modification n'est terminée qu'après validation du code, tests automatisés, CI verte, crash-tests locaux nécessaires validés par l'utilisateur et documentation à jour.

Les corrections courtes peuvent être poussées directement. Les chantiers longs passent par une PR draft ; une seule PR ouverte par dépôt, et aucune fusion tant qu'une dette technique bloquante subsiste.

Les tests sont répartis entre les dépôts (PHPUnit, tests CLI, tests frontend). Les commandes exactes figurent dans leurs `composer.json` et `package.json`.

Le protocole de validation avant releases est détaillé dans [les crash-tests du socle sur les quatre SGBD](ct-socle-quatre-sgbd.md). Il constitue une grille de recette, pas une validation exécutée.
