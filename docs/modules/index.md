# Modules SymSvel

Un module est une unité fonctionnelle réutilisable : backend Symfony, interfaces BO/App/Front selon les scopes, migrations, tests et documentation.

## Génération

```bash
symsvel make:module catalogue --scopes=back,bo,app
```

Le Maker impose une structure standardisée afin que le Tooling puisse intégrer les modules selon les conventions SymSvel. Le Back est toujours inclus. **Attention :** les profils simples `app` et `front` incluent le BO par compatibilité historique, mais une liste explicite de scopes conserve les scopes indépendants et n'ajoute pas automatiquement le BO.

| Scopes demandés | Structure |
| --- | --- |
| back | Back |
| bo | Back + BO |
| app | Back + BO + App |
| front | Back + BO + Front |
| app,front (liste explicite) | Back + App + Front, sans BO implicite |
| back,app (liste explicite) | Back + App, sans BO implicite |
| all (profil) | Back + BO + App + Front |

L'usage du Maker n'est pas obligatoire ; le respect du contrat du module l'est. Voir [le contrat](contrat.md).
