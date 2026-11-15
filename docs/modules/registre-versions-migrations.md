# Registre des versions de migrations des modules natifs

Ce registre est un aide-mémoire maintenu avec les migrations des modules SymSvel. **Les fichiers `Migration/VersionYYYYMMDDHHMMSS.php` des dépôts modules sont la source de vérité** : vérifier leurs noms avant toute attribution, car ce document peut être en retard.

## Versions relevées sur les branches `dev` le 15/11/2026

| Version | Module | Source |
| --- | --- | --- |
| `Version20200101000000` | Admins | `symsvel-module-admins/Migration/Version20200101000000.php` |
| `Version20200101000100` | Roles | `symsvel-module-roles/Migration/Version20200101000100.php` |
| `Version20200101000200` | ACLs | `symsvel-module-acls/Migration/Version20200101000200.php` |
| `Version20200101000230` | Users | `symsvel-module-users/Migration/Version20200101000230.php` |
| `Version20200101000300` | Roles | `symsvel-module-roles/Migration/Version20200101000300.php` |
| `Version20200101000400` | Settings | `symsvel-module-settings/Migration/Version20200101000400.php` |
| `Version20200101000500` | Auth (access tokens) | `symsvel-module-auth/Migration/Version20200101000500.php` |
| `Version20200101000501` | Auth (refresh tokens) | `symsvel-module-auth/Migration/Version20200101000501.php` |

## Règle d'attribution

1. Examiner les migrations `Migration/Version*.php` **de tous les modules concernés**, pas seulement du module modifié.
2. Choisir une version libre, unique à l'échelle de l'installation, en tenant compte de l'ordre des dépendances et des opérations intra-module.
3. Mettre à jour ce registre **dans le même chantier** que la nouvelle migration, ainsi que la documentation du module.
4. Vérifier l'absence de doublons avant compilation ; le compilateur refuse déjà une collision de versions Doctrine. Ne jamais renommer une migration déjà livrée/appliquée sans procédure de migration explicite.

Ce registre ne réserve pas automatiquement les numéros et ne remplace pas le contrôle du compilateur. Les versions des modules tiers ou futurs ne sont pas couvertes par cette liste ; elles doivent être vérifiées à l'installation.
