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
