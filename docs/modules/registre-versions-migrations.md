# Registre des versions de migrations des modules natifs

Ce registre est un aide-mémoire maintenu avec les migrations des modules SymSvel. **Les fichiers `Migration/VersionYYYYMMDDHHMMSS.php` des dépôts modules sont la source de vérité** : vérifier leurs noms avant toute attribution, car ce document peut être en retard.

## Versions relevées sur les branches `dev` au 16/11/2026

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
| `Version20261116000201` | ACLs (surcharges des libellés) | `symsvel-module-acls/Migration/Version20261116000201.php` |
| `Version20261116192000` | Languages (référentiel) | `symsvel-module-languages/Migration/Version20261116192000.php` |
| `Version20261116192100` | Languages (initialisation des 117 langues) | `symsvel-module-languages/Migration/Version20261116192100.php` |

## Règle d'attribution

1. Examiner les migrations `Migration/Version*.php` **de tous les modules concernés**, pas seulement du module modifié.
2. Choisir une version libre, unique à l'échelle de l'installation, en tenant compte de l'ordre des dépendances et des opérations intra-module.
3. Mettre à jour ce registre **dans le même chantier** que la nouvelle migration, ainsi que la documentation du module.
4. Vérifier l'absence de doublons avant compilation ; le compilateur refuse déjà une collision de versions Doctrine. Ne jamais renommer une migration déjà livrée/appliquée sans procédure de migration explicite.

Ce registre ne réserve pas automatiquement les numéros et ne remplace pas le contrôle du compilateur. Les versions des modules tiers ou futurs ne sont pas couvertes par cette liste ; elles doivent être vérifiées à l'installation.


## Surcharges de libellés ACLs (16/11/2026)

La nouvelle migration de `symsvel-module-acls` est **additive** et crée
`symsvel_acl_labels`, avec clé primaire composite (`resource`, `action`).
Elle ne modifie pas la migration initiale des règles d'autorisation. Les
versions des autres modules restent à vérifier à partir de leurs sources,
particulièrement sur les projets XT ayant des migrations déjà enregistrées.
Les validations requises sont CI puis compilation/exécution et CT sur les
SGBD du projet.


## Versions Languages vérifiées (16/11/2026)

Les deux migrations du module **natif** Languages ont été ajoutées
après vérification des chemins de migrations `Version*.php` sur les
branches `dev` des modules Admins, Roles, ACLs, Users, Settings, Auth,
Mails et Languages. Les versions `20261116192000` et `20261116192100`
ne chevauchent aucune de ces sources. La première utilise
`Doctrine\DBAL\Schema\Schema`, la seconde contient exclusivement
les insertions DML du référentiel natif, exécutées **après** la
création de la table. L'exécution multibase via le compilateur
SymSvel reste à confirmer au crash-test XT.


## Blocage de désinstallation des modules natifs (16/11/2026)

La transaction Composer refuse **avant toute prise de snapshot ou appel
Composer** la suppression d'un paquet déclaré obligatoire par
`backend/config/symsvel-native-modules.json`. Cette protection comprend
`symsvel-framework/module-languages` et les autres modules natifs
obligatoires, ainsi que les modules natifs de composants effectivement
installés (ex. Users si App est présent). Elle ne maintient aucune
liste dupliquée dans le Tooling : le manifeste Back est source de vérité.
Les tests vérifient un refus sans déclencher Composer ni créer de
checkpoint. Les projets de test isolés dépourvus de manifeste conservent
leur fonctionnement historique. Le CT final vérifiera le refus depuis
la CLI sur un projet réellement installé.
