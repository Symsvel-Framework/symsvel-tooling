# Publication du domaine BO dans la configuration Symfony

**Correctif du 16 novembre 2026.** Le Setup conserve les métadonnées du projet dans `<workspace>/.symsvel/project.json`. Ces métadonnées appartiennent à la CLI (permissions privées 0700 pour le répertoire et 0600 pour le fichier). Un processus PHP-FPM distinct n'a pas à les lire. Auth utilisait cette lecture lors de `POST /apibo/auth/browser/login`, provoquant un HTTP 403 avant toute validation des identifiants.

La classe `Update/LocalBoDomainPublisher` est exécutée **par la CLI** dans le parcours existant `symsvel:module:sync --source=composer`, notamment via `symsvel update`. Après validation du champ `local_domain` du Setup, elle publie uniquement `SYMSVEL_BO_DOMAIN="<domaine>"` dans `backend/.env.local`. Aucun secret privé, nom utilisateur ou identifiant n'est transféré. Le reste du contenu est préservé. L'opération est atomique, respecte les permissions déjà appliquées au fichier cible et ne réécrit rien si le résultat est identique.

Le bootstrap sans domaine de Setup, le projet dépourvu de `.env.local` et les mises à jour antérieures au wizard restent inchangés. Les chemins symboliques et les domaines non validés sont refusés. Les tests `LocalBoDomainPublisherTest` couvrent publication, idempotence, préservation des paramètres existants, réparation d'une ancienne valeur, bootstrap et refus des chemins symboliques.

Le Setup Back ajoute nativement cette même variable pour les nouvelles installations. Pour les anciens projets en canal `dev`, une mise à jour de Tooling et Auth déclenche la publication sans changer les permissions du dossier privé. Il faut ensuite que l'environnement Symfony du processus web soit rechargé normalement.

**Contrat côté Auth :** `TrustedBoOrigin` lit `SYMSVEL_BO_DOMAIN` depuis l'environnement runtime et refuse les requêtes sans domaine public valide. L'override `SYMSVEL_BO_ORIGIN` reste disponible pour un hôte BO HTTPS explicitement configuré. Aucun domaine n'est déduit du header `Host`. Sur HTTP en `dev`, les noms réservés locaux sont permis ; les noms DNS personnalisés requièrent en plus une connexion backend depuis la boucle locale. En production, le cookie de renouvellement reste `__Host-`, `Secure`, `HttpOnly`, `SameSite=Strict`.

**Validation restante :** CI Auth, Back et Tooling vertes, puis CT XT sur la connexion, le rechargement de session, les cookies, les origines rejetées et le routage CRUD.
