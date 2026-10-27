# Référence CLI : modules SymSvel

> État vérifié dans `symsvel-tooling`. Les commandes ci-dessous reflètent le code existant, avant l'intégration Composer/Flex. À intégrer à la documentation MkDocs du projet.

Exécuter depuis `backend/` avec `php bin/console`, sauf le maker autonome `./symsvel make:module` depuis le workspace.

| Commande (préfixe `php bin/console`) | Rôle | Arguments et options propres |
| --- | --- | --- |
| `symsvel:make:module <name>` | Génère un module local | `--workspace=...`, `--scopes=back\|bo\|app\|front\|all` (défaut back), `--vendor=...` (défaut symsvel-framework) |
| `symsvel:module:plan` | Vérifie et prévisualise le plan des modules | `--workspace=...`, `--json`, `--source=local|composer` (défaut `local`) |
| `symsvel:module:sync` | Publie l'inventaire et la carte des Bundles, **sans les activer** | `--workspace=...` |
| `symsvel:module:migrations:compile` | Compile les migrations sources non enregistrées | `--workspace=...`, `--execute` (exécute et enregistre les migrations) |
| `symsvel:module:migrations:recovery:status` | Consulte une récupération SQL en attente, sans modifier la base | `--workspace=...`, `--json` |
| `symsvel:module:migrations:recover` | Reprend les compensations possibles après incident SQL | `--workspace=...` |
| `symsvel:module:remove:plan <module>` | Prévisualise la suppression et les dépendants | `--workspace=...`, `--cascade`, `--json` |
| `symsvel:module:remove:migrations:plan <module>` | Prépare la désinstallation SQL et son empreinte de confirmation | `--workspace=...`, `--cascade`, `--json` |
| `symsvel:module:remove:migrations:execute <module>` | Exécute les migrations descendantes avec sauvegarde SQL | `--workspace=...`, `--cascade`, `--confirm=<empreinte>` |
| `symsvel:module:remove:recovery:status` | Affiche l'état d'une récupération de suppression | `--workspace=...` |

## Exemples

```bash
./symsvel make:module documents --scopes=all
php bin/console symsvel:module:plan --json
php bin/console symsvel:module:plan --source=composer --json
php bin/console symsvel:module:sync
php bin/console symsvel:module:migrations:compile
php bin/console symsvel:module:migrations:compile --execute
php bin/console symsvel:module:migrations:recovery:status
php bin/console symsvel:module:remove:migrations:plan documents
php bin/console symsvel:module:remove:migrations:execute documents --confirm=<empreinte_du_plan>
```

## Sécurité et ordre

- `plan` et `status` n'exécutent pas de SQL destructif.
- `migrations:compile` sans `--execute` génère du PHP Doctrine, sans l'exécuter.
- Les migrations sont ordonnées selon les dépendances des modules puis les noms de fichiers. Le `down` inverse cet ordre.
- Une suppression SQL exige une empreinte issue du plan ; `--cascade` inclut les modules dépendants.
- Après un échec SQL, examiner le statut de récupération avant toute nouvelle opération. Ne jamais supposer qu'une migration interrompue a été annulée automatiquement.
- Les options Symfony globales (`--env`, `--no-interaction`, etc.) ne sont pas détaillées ici.

## Évolution prévue

Lorsque les modules seront installés par Composer et intégrés via Symfony Flex, mettre à jour cette référence pour distinguer clairement commandes Composer, recettes Flex et commandes SymSvel. Ne pas présenter l'activation Composer/Flex comme déjà opérationnelle.
