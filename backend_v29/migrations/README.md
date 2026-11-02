Migrations are applied by code (idempotent, additive):
* Platform DB: `php tools/platform-setup.php migrate` (platform/schema.php, versions 1–2). Reference DDL: platform_schema_v2_reference.sql.
* Each branch / restaurant DB: Finance 360 migration 13 (new table f3_closing_files) via `migrate-f360.php` (web, owner) or
  `php tools/platform-migrate-branches.php --apply`. Reference DDL: branch_f360_migration_13_reference.sql.
* KSDD adoption (no financial table changes): `php tools/platform-adopt-legacy.php` (dry run) → `--apply --i-have-a-backup`.
Rollback: see docs/MIGRATION_DEPLOYMENT_ROLLBACK.md §7. No migration drops, truncates or rewrites existing tables.
