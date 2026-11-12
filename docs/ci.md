# Intégration continue

La branche `dev` est le terrain de développement ; `main` conserve la version stable.

Le workflow `.github/workflows/ci.yml` est déclenché par les `push` sur `dev` et `main`, ainsi que par les pull requests ciblant `main`. Les pull requests ciblant `dev` ne déclenchent pas de CI au titre de l'événement `pull_request` ; un `push` sur `dev` déclenche sa propre exécution. Les éventuels déclencheurs supplémentaires définis dans le workflow restent applicables.

Les jobs utilisent les runners auto-hébergés configurés dans le workflow. Une modification n'est validée qu'après exécution et réussite de la CI, puis les crash-tests locaux requis.

## Fixtures Git de tests

Les tests d'intégration de mise à jour native utilisent des dépôts Git temporaires avec une branche `dev`, conformément au canal de provenance `ProjectChannel::DEV` : le clone de `ComponentNativeSource` sélectionne explicitement `dev`. Les fixtures ne doivent pas créer uniquement `main`, faute de quoi les tests échouent avant d'atteindre leurs assertions métier. Les tests d'installation de composants déclarent explicitement le canal de leur backend temporaire avant d'exécuter le cycle de vie.

Dans les tests de cycle de vie, le fichier `.symsvel/project.json` est une donnée initiale de fixture. Les scénarios de refus vérifient qu'il reste intact et qu'aucun autre fichier n'apparaît dans `.symsvel`, plutôt que d'exiger l'absence du répertoire. Les dépôts locaux utilisés pour installer les composants sont initialisés sur `dev`.
