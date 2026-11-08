# Contrat de manifeste des modules SymSvel (schema 1)

Le manifeste `module.json` utilise `schema: 1`. Les manifests historiques XCore ne sont pas compatibles. Le contrat est vérifié par `ModuleManifest::read()` dans Tooling. Un module est développé dans son dépôt ou dans `modules/<slug>/`, puis installé par Composer ; **le Maker ne développe jamais dans `vendor/`**.

## Champs et validations

- `schema` : entier `1` obligatoire.
- `name` : nom PascalCase, commençant par une majuscule, lettres et chiffres.
- `components` : objet associatif des scopes `back`, `bo`, `app`, `front`, avec `back: "back"` obligatoire. Chaque répertoire déclaré doit exister et ne pas être un lien symbolique.
- `documentation` : objet associatif des scopes `dev`, `admin`, `user`, avec `dev: "docs/dev"` obligatoire. Chaque répertoire déclaré doit exister et ne pas être un lien symbolique.
- `dependencies` : tableau ou objet JSON requis ; le traitement précis des dépendances relève des planificateurs de modules.
- `extensions` : tableau ou objet JSON requis.
- `tables` : liste optionnelle de noms de tables uniques, composés de lettres, chiffres et underscores, commençant par une lettre ou un underscore.

Exemple minimal conforme :

```json
{
  "schema": 1,
  "name": "Documents",
  "dependencies": {},
  "tables": [],
  "components": {"back": "back"},
  "documentation": {"dev": "docs/dev"},
  "extensions": []
}
```

Cet exemple exige les dossiers physiques `back/` et `docs/dev/` à côté de `module.json`.

## Génération et intégration

```bash
symsvel make:module documents --scopes=back,bo,app
```

Le Maker génère un bundle Symfony sous `back/src/`, des répertoires de migrations et tests, les scopes Svelte demandés, la documentation associée et un **modèle** de recette Flex. Les profils historiques `back`, `bo`, `app`, `front`, `all` sont acceptés. Le scope `back` est toujours ajouté ; les profils `app` et `front` ajoutent le BO, contrairement à une liste explicite de scopes où seuls les scopes demandés (plus `back`) sont ajoutés.

Le modèle `recipe/recipe.json` contient une référence de commit à remplacer et **n'est pas automatiquement publié** dans le catalogue Flex. L'installation, les routes, la synchronisation Svelte et les migrations sont assurées par les mécanismes Tooling/Composer/Flex appropriés, pas par la simple génération du dossier du module.

Voir [les commandes](modules/commandes.md), [l'intégration des modules](modules/contrat.md) et [les transactions Composer](composer-transactions.md). Les crash-tests XT restent nécessaires pour valider le parcours complet sur les dépôts officiels.
