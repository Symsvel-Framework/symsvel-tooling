# SymSvel module contract v1

The SymSvel module manifest starts at **schema 1**. XCore manifests are historical references, not compatible contracts.

A module is a Composer package, developed in the workspace `modules/<slug>/` and installed in an application through Composer. The module maker never writes into `vendor/`.

Initial manifest keys:
- `schema: 1`: SymSvel contract version.
- `name`: PascalCase module name.
- `dependencies`: reserved for module dependencies.
- `components`: declared component directories (initially `back`).
- `documentation`: available documentation directories.
- `extensions`: declared extension points.

The initial maker generates a backend-only Symfony bundle. Frontend components, registration, lifecycle commands, asset publication and migrations will be added once their contracts are implemented. No implicit installation or publication is performed by the maker.

Usage from an installed SymSvel project:

```bash
php bin/console symsvel:make:module documents
```

Usage from a Symfony backend while targeting a different workspace:

```bash
php bin/console symsvel:make:module documents --workspace=/path/to/SymSvel
```

A standalone workspace launcher can delegate to the same maker service later.
