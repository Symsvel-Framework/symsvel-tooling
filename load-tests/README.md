# Banc de charge SymSvel (outil local)

Ce répertoire est **hors runtime** : aucun contrôleur, bundle, module, route, migration ni service de production n'est ajouté. Les essais sont exécutés volontairement par le développeur, à partir du poste de travail, avec **k6** installé séparément. Le projet SymSvel ciblé peut utiliser `APP_ENV=prod` tout en étant hébergé localement. L'outillage de test n'est pas déployé avec le Back.

## Préparation

Installer `k6` par le moyen de son choix (sur macOS, par exemple `brew install k6`). Cette commande est **manuelle** : SymSvel ne vérifie pas les services installés et n'installe aucun programme externe. Le script contrôle seulement que la commande `k6` est disponible lors du lancement.

Deux projets locaux séparés sont utiles pour comparer les environnements : un `dev` et un `prod`. Fixer autant que possible la même base de données, le même jeu de données et le même matériel/serveur pour la comparaison, sans faire porter au scénario un accès destructif à la BDD. Lorsque les deux projets sont testés sur le même Mac, ne charger **qu'un projet à la fois** pour éviter de fausser les mesures.

Avant un test, démarrer le serveur HTTP local, le SGBD prévu pour ce projet et effectuer manuellement une requête de contrôle sur **chaque** chemin demandé. Vérifier que ces URLs rendent un HTTP 200 sans session ni token. Les routes de Modules peuvent manquer selon les composants installés. Exemple de route métier facultative : `/apiopen/languages`.

## Exécution

Depuis la racine du clone **Tooling** :

```bash
# Contrôle rapide de l'interface (GET /).
bash load-tests/run.sh --url http://127.0.0.1:8000 --profile smoke --label prod-local

# Mesure de l'API publique (vérifier préalablement que la route répond 200).
bash load-tests/run.sh --url http://127.0.0.1:8000 --profile load \
  --paths /apiopen/languages --label prod-api

# Plusieurs chemins de lecture : le VU parcourt chacun dans l'ordre.
bash load-tests/run.sh --url http://127.0.0.1:8000 --profile stress \
  --paths '/,/apiopen/languages' --label prod-mix

# Nom de domaine local personnalisé, autorisé explicitement :
bash load-tests/run.sh --url https://mon-projet.local --profile smoke \
  --allow-non-loopback --label prod-domain
```

Le paramètre `--url` désigne **l'origine HTTP(S)** du Back ou d'une interface statique servie, sans chemin ni paramètre. `--paths` prend 1 à 12 chemins littéraux GET, séparés par des virgules. Aucun en-tête Authorization, cookie, identifiant, clé API, corps POST ou URL avec query string n'est accepté par ces scénarios initiaux. Tester séparément un domaine BO statique et une API Back s'ils ont des origines distinctes.

Sans option, seul le profil `smoke` est autorisé sur une cible localhost/127.0.0.1/::1 **explicitement fournie**. Un nom de domaine local autre que loopback, ou une adresse distante, est refusé sans `--allow-non-loopback`. Ce flag confirme la responsabilité et l'autorisation de l'opérateur, il ne garantit pas que le domaine résout réellement vers sa machine. **Ne jamais activer ce flag pour un service tiers sans autorisation écrite et limites convenues.** Les chemins invalides, le protocole non HTTP(S), les identifiants dans l'URL et les ports hors plage sont refusés.

## Profils

| Profil | Utilisateurs virtuels (VU) | Durée nominale | Objectif |
|---|---:|---:|---|
| `smoke` | 2 | 35 s | Vérifier un service joignable avant de charger |
| `load` | 10 → 30 → 50 | 4 min 30 s | Débit et p95 en charge modérée |
| `stress` | 25 → 75 → 150 → 250 | 8 min | Trouver les premières saturations |
| `spike` | 15 → 200 | 1 min 35 s | Résistance à une hausse brusque |
| `soak` | 20 | 22 min | Observer l'endurance et la dégradation |

Les VU sont **des utilisateurs simulés**, pas un nombre garanti de requêtes/s. Le scénario fait des GET successifs avec un délai de 300 ms entre chaque itération ; la cadence réelle varie selon la durée des réponses, le nombre de chemins et les ressources de la machine émettrice. Les durées nominales n'incluent pas l'éventuelle période d'arrêt gracieux.

Les seuils initiaux, **provisoires et non contractuels**, sont : échecs HTTP sous 1 %, erreurs serveur/connexion sous 1 %, p95 sous 800 ms et p99 sous 2 000 ms. Une saturation volontaire pendant `stress` peut faire échouer ces seuils : le rapport reste pertinent, ce n'est pas automatiquement un bug fonctionnel. Les HTTP 429 sont comptés séparément ; ils peuvent signaler une limitation volontaire. Les réponses 3xx et 4xx autres que 429 ne sont pas automatiquement acceptées par les vérifications de succès 2xx.

## Mesure et comparaison

Le lanceur affiche le résumé k6 et écrit un JSON horodaté sous `load-tests/results/` (ignoré par Git). Il n'enregistre **pas** de corps de réponse, de secrets ni de captures du BO.

```bash
python3 load-tests/compare.py load-tests/results/*-summary.json
```

Le comparateur (Python 3, bibliothèque standard) donne : nombre total de requêtes, requêtes/s, p95/p99, échecs HTTP, taux d'erreurs serveur/connexion et HTTP 429. Pour une comparaison sérieuse entre DEV et PROD locale, conserver la configuration du serveur, le nombre de processus PHP-FPM, le SGBD, le jeu de données, les routes testées et la puissance du générateur de charge. Noter CPU/RAM, saturation du serveur web et de PostgreSQL/MySQL, connexions, limites PHP-FPM, et température/throttling du Mac. Si générateur et serveur tournent sur le même Mac, **la capacité mesurée peut être limitée par le générateur lui-même**.

Procédure recommandée : `smoke` puis `load`, `stress`, `spike`, `soak` uniquement lorsque les essais précédents sont stables. Interrompre si la machine devient instable, si les erreurs persistantes sont anormales ou si les ressources système dépassent les limites choisies. Ne jamais exécuter ces tests en CI : les workflows ne vérifient que la syntaxe, les arguments et le comparateur avec un exécutable k6 simulé.

**Interprétation :** on cherche le débit réel soutenable avec une latence et des erreurs acceptables, puis le premier profil où les seuils ne sont plus respectés. Une mesure « 250 VU atteints » n'est pas une preuve de capacité à 250 utilisateurs simultanés en situation métier, surtout avec des GET publics seulement. Les parcours authentifiés, les écritures BDD et l'envoi Brevo réel sont volontairement hors de ce premier banc.

## Validation

- `bash -n load-tests/run.sh load-tests/test-runner.sh` : syntaxe du lanceur.
- `bash load-tests/test-runner.sh` : contrôles de sécurité des arguments, **sans aucune requête HTTP**.
- `python3 -m unittest discover -s load-tests -p 'test_*.py'` : génération des tableaux sur résumés fictifs.
- `node --input-type=module --check < load-tests/scenarios.js` : syntaxe du script k6, sans exécution.
- Lancement réel des profils et interprétation des résultats : **crash-tests locaux à effectuer par l'utilisateur**.

Cette approche respecte les ADR 0000, 0006 et 0010 : outillage séparé, absence de complexité runtime et validation par CI + CT + documentation.
