# Protocole XT : quatre installations vierges avant releases

**Statut au 19 novembre 2026 : non exécuté, à valider par l'utilisateur.**

Ce protocole est la barrière entre les branches `dev` et la préparation des
releases stables. Une CI verte ne remplace aucun des quatre crash-tests locaux.
Ne publier aucune nouvelle release, ne modifier aucun `dev-dev` et ne merger
aucune PR avant validation explicite des quatre moteurs.

## Matrice d'installations

| Moteur | Projet vierge indépendant | Setup + migrations + seeds | BO / App / Front | Opérations et récupération | Validation XT |
| --- | --- | --- | --- | --- | --- |
| PostgreSQL | à créer | à tester | à tester | à tester | en attente |
| MySQL | à créer | à tester | à tester | à tester | en attente |
| MariaDB | à créer | à tester | à tester | à tester | en attente |
| SQLite | à créer | à tester | à tester | à tester | en attente |

Un projet distinct, une base neuve et des identifiants propres sont requis
pour chaque moteur. Interdire la réutilisation d'un `vendor/`, de migrations
compilées ou d'une base issus d'une installation précédente. Tout échec doit
être reproduit avec les sources du framework, jamais résolu par un patch local
dans XT.

## Phase A : création et Setup

1. Contrôler l'environnement par `symsvel doctor`.
2. Créer le projet avec le canal développement
   (`symsvel create-project --components=all --dev`), sans dépôt local
   clandestinement substitué à la distribution déclarée.
3. Exécuter le Setup local dans le navigateur et sélectionner le moteur,
   l'adresse et le premier administrateur. Le premier `ROLE_DEV` doit être
   unique, actif et son adresse approuvée à la fin du Setup.
4. Contrôler l'ordre : création base, compilation/migrations Doctrine
   portables, paramètres Settings, import des dictionnaires i18n, builds
   statiques BO/App/Front. Aucun build documentaire, venv Python ou MkDocs
   ne doit être déclenché par Setup.
5. Vérifier les tables de tous les modules natifs, leurs migrations versionnées,
   la présence du seed Countries (249 codes ISO distincts) et le catalogue
   Languages. Refuser tout conflit de version ou migration silencieusement
   ignorée.
6. Vérifier le fonctionnement de `GET /api/health` sans extrapoler sa réponse
   à la disponibilité de PostgreSQL, du transport SMTP ou des queues.

## Phase B : parcours natifs

Contrôler séparément BO, App et Front, y compris les endpoints, routes et
builds réellement servis en HTTP. Vérifier les connexions Auth, la 2FA
obligatoire BO et la politique App configurée, les refus sans permission et
la révocation des tokens. Dans Admins/Roles/ACLs, tester invitation,
activation du compte, rôle assignable, matrice ACL, comptes protégés et
pagination. Un administrateur `ROLE_DEV` doit pouvoir modifier son propre
mot de passe, sa langue et son adresse après validation de la nouvelle boîte,
sans pouvoir être supprimé comme dernier DEV.

Vérifier les flux Users/App, mails (Mailpit en développement si configuré),
Settings, Languages (dictionnaires et fallback), Countries (présent en
monolingue côté API publique mais absent de navigation BO ; administration
visible après activation du multilinguisme). Valider les composants du
Design System BO en thème clair/sombre, actions et états désactivés.

Exécuter volontairement `symsvel documentation:sync` **après** Setup.
Vérifier ensuite l'accès `/documentation` dans le menu du BO,
la consultation du corpus à travers l'API authentifiée et le refus de
lecture des documents `dev` pour un administrateur non-`ROLE_DEV`.
Contrôler que le Markdown ne peut injecter du HTML actif et que les
documents privés ne figurent ni dans `public/` ni dans les builds Svelte.

## Phase C : maintenance et incidents

Valider les commandes CLI `symsvel require`, `symsvel update`,
`symsvel remove` et `symsvel make:module` avec un module local de CT
et un package distribué. Pour les routes Symfony, créer un contrôleur
explicite dans le module de CT et vérifier son import automatique :
`debug:router` et `router:match` en maintenance interne, puis une
requête HTTP. Refaire la synchronisation, mettre à jour, désinstaller
et vérifier que les seules routes générées disparaissent. Ne pas toucher
aux routes propres au projet et à Flex.

Provoquer une erreur contrôlée pendant la synchronisation Composer,
l'installation d'un module, la migration et le build ; examiner le
diagnostic, les sauvegardes, le rollback et la possibilité d'une reprise.
Ne déclarer aucun rollback « total » si des données/fichiers restent
modifiés. Tester les collisions de fichiers projet, les modifications
locales, les relations entre modules et les refus de suppression des
modules natifs obligatoires.

## Validation et passage en stable

Conserver par moteur : date, commits `dev` testés, version PHP/Symfony,
capture de Setup, nombre de migrations, résultats HTTP et E2E, anomalies
résolues dans les dépôts sources, CI finales correspondantes et validation
explicite XT.

**Les quatre lignes doivent être validées par l'utilisateur** avant la
phase 3 (préparation des dépendances stables), les merges vers `main`
et les tags/releases. Un cinquième CT distinct sera effectué après
publication, sur une installation vierge **sans `--dev`** avec les
packages réellement distribués via Satis et Flex.
