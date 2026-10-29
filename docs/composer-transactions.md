# Composer transactions (experimental)

The transactional runner is **opt-in**. Native `composer require`, `composer update`, and
`composer remove` are **not intercepted**.

Once `symsvel:update` has published the entrypoint into the consumer backend,
run from `backend/`:

```bash
php scripts/symsvel-composer-transaction.php require symsvel-framework/module-test-back:dev-main
php scripts/symsvel-composer-transaction.php update symsvel-framework/module-test-back
php scripts/symsvel-composer-transaction.php remove symsvel-framework/module-test-back
```

The runner snapshots `composer.json` and `composer.lock` under
`.symsvel/transactions/<id>/`. On success, it removes the snapshot.
If Composer exits with an error, it restores both manifests and executes
`composer install --no-scripts --no-plugins --no-interaction` to reconcile
`vendor/` with the **original lockfile**.

**Limitations:** This is not an atomic transaction. Composer plugins (including
Symfony Flex) may have modified other files during the original operation.
Database migrations, filesystem changes outside Composer's managed files,
external services and generated Svelte files are not rolled back. A missing
initial `composer.lock` prevents deterministic vendor restoration.
If recovery fails, the snapshot is retained and the command exits nonzero.
Never treat a failed recovery as a successful installation.

This first iteration deliberately does not alter Composer's native commands.
A Composer plugin or an explicit SymSvel CLI entrypoint would be needed for
transparent interception of those commands.
