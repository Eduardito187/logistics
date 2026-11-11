# Respaldo y restauración de la base — logistics

Runbook del **mecanismo oficial** de backup: los comandos artisan `backup:create`, `backup:verify` y
`backup:restore`. Son **manuales** (no hay scheduler ni cron de backups en la app) y respaldan la base
**PostgreSQL 17 + PostGIS completa**.

> El backup automático (servicio `pg-backup`) está **desactivado**; opcional:
> `docker compose --profile backup up -d pg-backup`, ver `.docker/backups/README.md`.

Los respaldos contienen PII: se tratan como dato sensible (ver `docs/security.md` y §7).

---

## 1. Qué incluye y qué NO

**Incluye: la base de datos completa, estructura + datos**, de todos los schemas de usuario:

- tablas, tablas particionadas y sus particiones, con **todas sus filas**;
- índices, constraints (PK, FK, UNIQUE, CHECK, EXCLUDE), secuencias **con su valor actual**;
- vistas, vistas materializadas, funciones/procedimientos, triggers;
- extensiones (`postgis`, `pg_trgm`, …) y las filas de usuario de sus tablas de configuración
  (p. ej. `spatial_ref_sys`).

**NO incluye:**

- **archivos subidos** (POD, evidencias, documentos y fotos de flota, discos `local`/`public`/S3);
- **volúmenes** de Docker, **contenedores** ni **imágenes**;
- roles/usuarios de Postgres (son globales del servidor, no de la base) y ajustes
  `ALTER DATABASE … SET` (se **registran** en el manifest en `database.settings` para reaplicarlos a mano).

Dueño/permisos: el dump es `--no-owner --no-privileges`; al restaurar, todo queda a nombre del usuario
que restaura. Es portable entre entornos (dev ↔ staging ↔ prod).

---

## 2. Comandos (contrato)

Todos corren dentro del contenedor de la app (`docker compose exec -T app php artisan …`). En Git Bash
(Windows) anteponer `MSYS_NO_PATHCONV=1` si se pasan rutas tipo `/tmp/...`.

| Comando | Qué hace | ¿Toca la base viva? |
| --- | --- | --- |
| `backup:create {--path=} {--connection=} {--host=} {--port=}` | Genera el bundle `.tar` (dump + manifest) | No (solo lee) |
| `backup:verify {bundle} {--connection=} {--host=} {--port=}` | Restaura en una base scratch y compara contra el manifest | No |
| `backup:restore {bundle} {--force} {--connection=} {--host=} {--port=}` | Restore seguro por swap | Sí (la anterior queda para rollback) |
| `db:backup {--path=} {--connection=} {--host=} {--port=}` | Compatibilidad: `.dump` + `<archivo>.manifest.json` al lado | No |
| `db:restore {file} {--force} {--connection=} {--host=} {--port=}` | Compatibilidad: mismo camino seguro que `backup:restore` | Sí (idem) |

Todos terminan con **exit ≠ 0** ante cualquier error o diferencia.

```bash
docker compose exec -T app php artisan backup:create
docker compose exec -T app php artisan backup:verify storage/app/backups/logistics-20261111-030000.tar
docker compose exec -T app php artisan backup:restore storage/app/backups/logistics-20261111-030000.tar
```

### Conexión: directo a Postgres, nunca por PgBouncer

PgBouncer en transaction pooling no soporta snapshots exportados ni el DDL de sesión del swap. Orden
de resolución del host/puerto:

1. `--host` / `--port` del comando;
2. `DB_DIRECT_HOST` / `DB_DIRECT_PORT` (los mismos de la conexión `pgsql_direct`);
3. el host/puerto de la conexión; si parece PgBouncer (puerto `6432` o host `*bouncer*`), se
   **descubre** el Postgres real con `inet_server_addr()` y se avisa por consola para fijar `DB_DIRECT_*`.

Usuario, clave y base salen de la conexión (`--connection`, por defecto la default). La clave viaja en
`PGPASSWORD` (entorno del proceso), nunca en la línea de comandos.

### Versión del cliente

`pg_dump`/`pg_restore` deben tener major **>=** al del servidor; si no, el comando falla con un error
claro antes de hacer nada (un cliente viejo no entiende el catálogo nuevo). La imagen de la app trae
cliente 18 contra servidor 17.

---

## 3. `backup:create` — el bundle

Salida por defecto: `<BACKUP_PATH>/logistics-<YYYYmmdd-HHMMSS>.tar` (`BACKUP_PATH` por defecto
`storage/app/backups`). Es un `.tar` sin comprimir (el dump ya va comprimido) con dos miembros:

| Miembro | Contenido |
| --- | --- |
| `database.dump` | `pg_dump --format=custom --compress=9 --no-owner --no-privileges` de la base completa |
| `manifest.json` | Descripción verificable del dump (abajo) |

Cómo garantiza que el manifest describe **exactamente** lo que entró al dump: abre una transacción
`REPEATABLE READ READ ONLY`, exporta su snapshot (`pg_export_snapshot()`), lee el inventario, corre
`pg_dump --snapshot=<ese snapshot>` y cuenta filas en esa misma transacción. Aunque haya escrituras
concurrentes, dump y conteos ven los mismos datos.

El bundle se escribe como `<archivo>.tmp` y se renombra al final: si algo falla, se borra el parcial
y nunca queda un archivo a medias con el nombre final.

### `manifest.json` (formato 1)

```json
{
  "format": 1,
  "kind": "postgresql-database",
  "scope": "Base PostgreSQL completa (estructura + datos). NO incluye archivos subidos, …",
  "created_at": "2026-11-11T10:06:18-04:00",
  "app_env": "local",
  "git_commit": "3ba13fa…",                 // null si no se pudo obtener
  "database": {
    "name": "logistics",
    "server_version": "17.11", "server_version_num": 170011,
    "pg_dump_version": "pg_dump (PostgreSQL) 18.6",
    "file": "database.dump", "size": 860127, "sha256": "35aff5…",
    "settings": [],                          // ALTER DATABASE … SET (no van en el dump)
    "inventory": {
      "schemas": ["public"],
      "tables": ["public.users", …],         // tablas hoja (incluye particiones)
      "partitioned_tables": ["public.…"],
      "indexes": […], "constraints": […], "views": […], "materialized_views": […],
      "functions": ["public.fn(args)", …], "triggers": ["public.tabla.trigger", …],
      "sequences": {"public.users_id_seq": 8, "public.jobs_id_seq": null},
      "extensions": {"postgis": "3.5.7", "pg_trgm": "1.6", "plpgsql": "1.0"}
    },
    "row_counts": {"public.users": 8, …},    // count(*) EXACTO por tabla
    "summary": {"tables": 120, "indexes": 511, …, "row_total": 16656}
  }
}
```

Criterios (idénticos en create, verify y restore):

- **Schemas de usuario** = todos menos `pg_catalog`, `information_schema`, `pg_toast*`, `pg_temp*`.
- Se **excluyen** del inventario los objetos miembros de una extensión (los recrea `CREATE EXTENSION`).
  Las tablas de configuración de extensión (`extconfig`, p. ej. `spatial_ref_sys`) sí entran en
  `row_counts`, porque pg_dump respalda sus filas de usuario.
- **Particionadas:** se cuentan las **hojas** (particiones, `relkind 'r'`) y **no** el padre
  (`relkind 'p'`), para no duplicar filas.
- **Secuencias:** se leen justo antes del dump; como no son transaccionales, al verificar se exige que el
  valor restaurado sea **>=** al del manifest (una escritura concurrente solo puede adelantarlas; quedar
  atrás significaría claves duplicadas).
- **Extensiones:** se comparan por nombre (la versión puede variar si el servidor destino trae otra).
- Constraints `NOT NULL` catalogados (PG18+) se excluyen, para comparar entre majors.

---

## 4. `backup:verify` — prueba no destructiva

1. Extrae el bundle y valida el **sha256** del dump contra el manifest.
2. Crea una base **scratch** `<db>_verify_<YYYYmmdd_HHMMSS>` en el mismo servidor con
   `TEMPLATE template0` (vacía: sin las extensiones que la imagen de PostGIS mete en `template1`).
3. `pg_restore --exit-on-error` (sin `--clean`) en la scratch.
4. Compara contra el manifest: tablas del TOC del dump, inventario de estructura completo y conteo de
   filas por tabla. Imprime una tabla `Item | Expected | Actual` con cada diferencia.
5. **Dropea la scratch siempre** (también si falló). El drop tiene un guard duro: solo acepta nombres
   `*_verify_<ts>` / `*_restore_<ts>`; jamás la base viva ni una `_prev_`.

Exit 0 solo si todo coincide. Un manifest alterado o un dump corrupto hacen fallar la verificación.

---

## 5. `backup:restore` — restore seguro por swap

**Antes:** `php artisan down` y frenar workers/scheduler (si la app escribe tras el swap, la verificación
final lo detecta como diferencia). Pide confirmación (`--force` la salta); en `APP_ENV=production` exige
`--force`.

1. Extrae el bundle, valida sha256 y la versión del cliente.
2. Restaura en una base **nueva** `<db>_restore_<ts>` (`TEMPLATE template0`, sin `--clean`).
3. Verifica estructura + conteos contra el manifest. Si algo no coincide: **aborta**, dropea la scratch y
   la base viva **no se tocó**.
4. Swap, conectado a la base de mantenimiento `postgres` (no se puede renombrar la base a la que uno
   está conectado): `ALTER DATABASE <db> ALLOW_CONNECTIONS false`, `pg_terminate_backend` de las sesiones
   de ambas bases, `<db>` → `<db>_prev_<ts>`, `<db>_restore_<ts>` → `<db>`. Si el segundo rename falla,
   deshace el primero. Si `<db>` no existía, solo renombra la nueva.
5. Verificación final: el nombre `<db>` apunta a la base restaurada (mismo oid) y su inventario + conteos
   coinciden con el manifest.

La base anterior queda como `<db>_prev_<ts>` con `ALLOW_CONNECTIONS false`. El comando imprime cómo
volver atrás y cómo borrarla; desde `psql` en la base `postgres`:

```sql
-- Volver atrás (si dice que la base está en uso: SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'logistics';)
ALTER DATABASE "logistics" RENAME TO "logistics_discarded";
ALTER DATABASE "logistics_prev_20261111_100752" WITH ALLOW_CONNECTIONS true;
ALTER DATABASE "logistics_prev_20261111_100752" RENAME TO "logistics";

-- Borrar la anterior cuando ya no haga falta
DROP DATABASE "logistics_prev_20261111_100752";
```

> Por qué no `pg_restore --clean`: sobre una base creada por la imagen `postgis/postgis` existen
> `postgis_topology` y `postgis_tiger_geocoder` (dependen de `postgis`); el `DROP EXTENSION IF EXISTS
> postgis` del `--clean` falla y, con `--single-transaction`, **no se importaba nada**. Restaurar en una
> base nueva desde `template0` elimina el problema de raíz y además deja la base viva intacta hasta que
> la nueva está verificada.

Requisitos del usuario de restore: `CREATEDB`, ser dueño (o superusuario) de la base viva para
renombrarla/terminar sesiones, y poder crear las extensiones del dump (PostGIS requiere superusuario o
un rol con permiso según el proveedor).

Espacio: durante el restore conviven la base viva, la nueva y el bundle extraído; prever ~2x el tamaño
de la base libre en el servidor.

---

## 6. Compatibilidad: `db:backup` / `db:restore`

- `db:backup` escribe `backup-<db>-<ts>.dump` (en `BACKUP_PATH`) y `<archivo>.manifest.json` al lado, con
  el mismo manifest formato 1 y el mismo snapshot. Directo a Postgres.
- `db:restore <archivo>.dump` usa el **mismo camino seguro** (scratch + verificación + swap). Si existe
  `<archivo>.manifest.json`, verifica sha256, estructura y conteos; si no (dump viejo), verificación
  **estructural**: toda tabla del TOC del dump debe existir en la base restaurada.

---

## 7. Configuración (`config/backup.php`)

| Env | Default | Para qué |
| --- | --- | --- |
| `BACKUP_PATH` | `storage/app/backups` | Directorio por defecto de bundles y dumps |
| `DB_DIRECT_HOST` / `DB_DIRECT_PORT` | — | Postgres real (sin PgBouncer); recomendado fijarlos |
| `BACKUP_MAINTENANCE_DATABASE` | `postgres` | Base desde la que se hace CREATE/DROP/RENAME DATABASE |
| `BACKUP_TIMEOUT` | `7200` | Timeout (s) de pg_dump / pg_restore / tar |

Guarda y acceso: `storage/app/backups` no se versiona. En producción guardar los bundles fuera del
servidor de base, en almacenamiento **cifrado en reposo**, con acceso restringido a operaciones; para
copia fuera de sitio, cifrarlos (`age`/`gpg` o cifrado del bucket).

> Los scripts `scripts/backup-db.sh` / `scripts/restore-db.sh` son anteriores a este mecanismo; el
> oficial son los comandos artisan de este documento.

---

## 8. Prueba de restauración y recuperación ante desastre

- **Prueba periódica** (y tras tocar este código): `backup:create` + `backup:verify`. Es no destructiva y
  prueba el ciclo real dump → restore → comparación.
- **Desastre:**
  1. Aprovisionar PostgreSQL 17 + PostGIS (la base `logistics` puede existir vacía o no existir).
  2. Traer el último bundle bueno.
  3. `php artisan backup:verify <bundle> --host=<pg> --port=5432`.
  4. `php artisan backup:restore <bundle> --host=<pg> --port=5432 --force`.
  5. Recrear roles/ajustes de base si hacían falta (ver `database.settings` del manifest), restaurar
     los archivos subidos desde su propio respaldo (S3/volumen; no son parte de este backup) y hacer un
     smoke test de la app.
