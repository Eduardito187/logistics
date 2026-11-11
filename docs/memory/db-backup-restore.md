---
name: db-backup-restore
description: "Backup MANUAL y completo de PostgreSQL (backup:create/verify/restore por swap, manifest con conteos+estructura); bugs que tenía el viejo db:restore"
metadata:
  node_type: memory
  type: project
  originSessionId: 5ae2cd97-4f6d-4a3c-8c55-472d9c313fac
  modified: 2026-11-11T14:24:32.871Z
---

Rehecho el 2026-11-11 (commit e486b89 del dueño). Alcance decidido por el dueño: **solo la base**
(estructura completa + datos), nada de archivos/volúmenes/contenedores/imágenes, y **solo manual** (ver
[[backup-manual-only]]). Doc: `docs/backup-restore.md`.

**Comandos (en el contenedor `app`):**
- `php artisan backup:create [--path=] [--connection=] [--host=] [--port=]` → `storage/app/backups/logistics-<YYYYmmdd-HHMMSS>.tar`
  con `database.dump` (pg_dump -Fc) + `manifest.json`. Escribe a `.tmp` y renombra; si falla borra el parcial.
- `php artisan backup:verify <tar>` → NO destructivo: sha256 del dump, restaura en `<db>_verify_<ts>`
  (TEMPLATE template0), compara TOC + estructura + conteos contra el manifest, dropea la scratch SIEMPRE. Exit≠0 con tabla de diferencias.
- `php artisan backup:restore <tar> [--force]` → restaura en `<db>_restore_<ts>`, verifica (si no cuadra aborta
  y la viva NO se toca), SWAP conectado a la base `postgres` (ALLOW_CONNECTIONS false + pg_terminate_backend +
  rename viva→`<db>_prev_<ts>`, nueva→`<db>`), verificación final. La `_prev_` queda para rollback. Antes:
  `artisan down` + parar workers (escrituras durante el swap hacen fallar la verificación final).
- `db:backup` / `db:restore` siguen (dump + `<file>.manifest.json`); `db:restore` usa el mismo camino seguro.
- Manifest formato 1: versiones server/pg_dump, sha256, settings, inventario (schemas, tablas, particionadas,
  índices, constraints, vistas, matviews, funciones, triggers, secuencias+last_value, extensiones) y
  row_counts exactos. Todo del MISMO snapshot (`pg_dump --snapshot`). Particiones: se cuentan las hojas.
- Piezas: `app/Support/Database/{PgEndpoint,PostgresBackup,PostgresAdmin,DatabaseBackupService,BackupBundle,BackupManifest}.php`,
  comandos `app/Console/Commands/Backup*Command.php` + trait `BackupCommandSupport`, `config/backup.php`
  (`BACKUP_PATH`, `BACKUP_MAINTENANCE_DATABASE`=postgres, `BACKUP_TIMEOUT`=7200). Guard duro: solo dropea `*_verify_<ts>`/`*_restore_<ts>`.
- Conecta DIRECTO a Postgres (`DB_DIRECT_HOST`/`DB_DIRECT_PORT`, agregados al `.env` local); si solo ve
  PgBouncer descubre el real con `inet_server_addr()` y avisa.
- Tests: `tests/Unit/Support/BackupManifestTest.php`, `tests/Feature/Console/DatabaseBackupCommandTest.php`,
  `tests/Feature/Console/BackupBundleIntegrationTest.php` (real, se salta si pg_dump falta o es más viejo que el server).

**Bugs del diseño viejo (por qué se rehizo):**
1. `pg_restore --clean --if-exists --single-transaction` FALLABA SIEMPRE sobre una base recién creada por
   `postgis/postgis` (trae `postgis_topology`/`postgis_tiger_geocoder` que dependen de postgis → `DROP EXTENSION
   postgis` falla → rollback total → no importaba NADA). Reproducido.
2. Iba por PgBouncer (`DB_HOST`). 3. Sin verificación de completitud. 4. La imagen `prod` no traía
   `postgresql-client` (agregado; pg_dump 18.6). Dumps de pg_dump 18 NO los lee pg_restore 17 → restaurar con la app.

Verificado en dev: 120 tablas, 511 índices, 185 constraints, 13 secuencias, 3 extensiones, 16.658 filas,
bundle 900 KB, verify OK sin bases residuales. Ver [[no-destructive-db-ops]], [[pg-pgbouncer-gotchas]].
