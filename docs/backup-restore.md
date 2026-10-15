# Respaldo y restauración de la base — logistics

Runbook operativo para respaldar y restaurar **PostgreSQL 17 + PostGIS**. Cubre los dos scripts
del repo, la prueba periódica de restauración y el schedule sugerido.

- Respaldo: `scripts/backup-db.sh`
- Restauración: `scripts/restore-db.sh`

Complementa `docs/deploy.md` (topología y retención de objetos en S3) y `docs/security.md` (los
respaldos contienen PII y se tratan como dato sensible). Todo corre en contenedores; nunca se usa
el PostgreSQL del host (los scripts se ejecutan **desde el host**, contra el contenedor o contra
la DB gestionada).

> Resumen en una línea: `backup-db.sh` deja un `.dump.gz` (formato custom de pg_dump, apto para
> PostGIS) con checksum y rotación; `restore-db.sh` lo restaura con DROP/CREATE y **verifica**
> tablas y PostGIS. Probamos el respaldo restaurándolo, no asumiéndolo.

---

## 1. Por qué formato custom + gzip

- `pg_dump --format=custom` (no plano) permite `pg_restore` selectivo y en paralelo, reordena
  dependencias y trae las extensiones (PostGIS) en el orden correcto.
- pg_dump escribe **sin** compresión interna (`--compress=0`) y la compresión la hace **gzip**
  por fuera, dejando un `.dump.gz` estándar: se valida con `gzip -t` y se destapa con `gunzip`.
- `--no-owner --no-privileges`: el restore recrea objetos bajo el rol que restaura, sin depender
  de los roles del origen. Esto hace portable el respaldo entre entornos (dev ↔ staging ↔ prod).

---

## 2. Modos de ejecución

Ambos scripts toman la conexión de variables de entorno y funcionan en dos modos:

| Modo | Cuándo | Cómo conecta |
| --- | --- | --- |
| `container` (default) | Desarrollo / staging con docker-compose | `docker exec` al contenedor `dismac_lg_postgres` (puerto interno 5432) |
| `direct` | Producción con DB gestionada | `pg_dump`/`pg_restore`/`psql` del host contra `DB_HOST:DB_PORT` |

Variables de conexión (defaults de desarrollo entre paréntesis):

```dotenv
BACKUP_MODE=container            # container | direct
PG_CONTAINER=dismac_lg_postgres  # solo modo container
DB_HOST=127.0.0.1                # solo modo direct (en container es interno)
DB_PORT=5432
DB_DATABASE=logistics
DB_USERNAME=logistics
DB_PASSWORD=logistics
```

`PGPASSWORD` viaja como variable de entorno hacia el binario, **nunca** en la línea de comandos
(no queda en `ps` ni en el historial de shell).

---

## 3. Respaldo — `backup-db.sh`

```bash
# Desarrollo / staging (modo container)
scripts/backup-db.sh

# Producción (DB gestionada, modo direct). El env se exporta desde el gestor de secretos.
BACKUP_MODE=direct \
DB_HOST=db.prod.interno DB_PORT=5432 \
DB_DATABASE=logistics DB_USERNAME=logistics DB_PASSWORD=*** \
BACKUP_DIR=/var/backups/logistics \
scripts/backup-db.sh
```

Variables de respaldo:

| Variable | Default | Para qué |
| --- | --- | --- |
| `BACKUP_DIR` | `./backups` | Carpeta destino. En prod, fuera del repo (p. ej. `/var/backups/logistics`) |
| `BACKUP_RETENTION_DAYS` | `14` | Borra respaldos más viejos que N días |
| `BACKUP_RETENTION_MIN` | `7` | Conserva siempre al menos N respaldos, pase lo que pase |
| `GZIP_LEVEL` | `6` | Nivel de compresión gzip (1..9) |

Salida por corrida:

- `BACKUP_DIR/logistics-YYYYmmdd-HHMMSS.dump.gz`
- `...dump.gz.sha256` (checksum para verificar integridad antes de restaurar)

Garantías:

- Escribe primero un `.partial` y solo lo renombra al final: **nunca** deja un respaldo a medias.
- Valida `gzip -t` y que el archivo no esté vacío antes de dar OK.
- Si `pg_dump` falla, sale con código ≠ 0 y **no** rota nada.

> Si se usa `BACKUP_DIR` dentro del repo, agregar esa carpeta a `.gitignore`: los respaldos
> contienen PII y jamás deben entrar al control de versiones. En producción debe vivir fuera del
> repo y en almacenamiento cifrado.

### Cifrado y acceso

Los respaldos replican el PII de la base (direcciones, contactos). En producción:

- Guardarlos en volumen/bucket **cifrado en reposo**; restringir acceso al rol de operaciones.
- Para copia fuera de sitio, subirlos cifrados (p. ej. `age`/`gpg` o cifrado del bucket) a un
  destino distinto del de producción.

---

## 4. Restauración — `restore-db.sh`

**DESTRUCTIVO:** hace DROP + CREATE de la base destino antes de restaurar.

```bash
# Interactivo (pide escribir el nombre de la base para confirmar)
scripts/restore-db.sh ./backups/logistics-20261013-030000.dump.gz

# No interactivo (automatización / pruebas)
CONFIRM=yes scripts/restore-db.sh ./backups/logistics-20261013-030000.dump.gz
# o
scripts/restore-db.sh --yes ./backups/logistics-20261013-030000.dump.gz
```

Variables propias de la restauración:

| Variable | Default | Para qué |
| --- | --- | --- |
| `PG_MAINTENANCE_DB` | `postgres` | Base desde la que se corre el DROP/CREATE |
| `RESTORE_JOBS` | `2` | Paralelismo previsto de pg_restore (restaura single-stream desde pipe) |
| `CONFIRM` / `--yes` | `no` | Salta la confirmación interactiva |
| `ALLOW_PROD` | `no` | Permite restaurar cuando db/host/env huelen a producción |

Salvaguardas:

- Verifica el `.sha256` del respaldo (si existe) y `gzip -t` **antes** de tocar la base.
- Corta conexiones activas antes del DROP.
- Se **niega** a correr si `DB_DATABASE`/`DB_HOST`/`APP_ENV` contienen `prod`/`production`, salvo
  `ALLOW_PROD=yes` explícito.
- `pg_restore --exit-on-error`: aborta ante el primer fallo real, no deja una base a medias en
  silencio.

### Verificación automática

Al terminar, el script comprueba y reporta:

1. Que haya **tablas** en el esquema `public` (> 0).
2. La **versión de PostGIS** instalada (avisa si no está, por si el origen no la usaba).

Ejemplo real de una restauración de prueba sobre este repo:

```
[restore-db] tablas en 'public': 67
[restore-db] PostGIS presente: 3.5.7
[restore-db] restauración verificada.
```

---

## 5. Prueba de restauración (DR drill)

Un respaldo no probado no es un respaldo. **Mensualmente** (y tras cualquier cambio en estos
scripts) se restaura el último respaldo a una base **desechable** y se verifica, sin tocar la
base real:

```bash
# Restaurar el último respaldo a una base aparte (no 'logistics' ni 'logistics_test')
F=$(ls -t ./backups/*.dump.gz | head -1)
DB_DATABASE=logistics_restore_test CONFIRM=yes scripts/restore-db.sh "$F"

# (opcional) comprobaciones extra sobre la base restaurada, luego limpiarla
docker compose exec -T postgres psql -U logistics -d postgres \
  -c 'DROP DATABASE IF EXISTS logistics_restore_test;'
```

La prueba pasa si: el checksum valida, `pg_restore` termina sin error, hay tablas y PostGIS
aparece. Se registra fecha, tamaño del respaldo y resultado. Si falla, es incidente: se revisa el
respaldo y el procedimiento antes de confiar en él.

> Nunca usar como destino de prueba `logistics` (desarrollo) ni `logistics_test` (lo vigila
> `TestDatabaseIsolationTest`). Usar un nombre dedicado y borrarlo al final.

---

## 6. Schedule sugerido

| Qué | Cuándo | Cómo |
| --- | --- | --- |
| Respaldo completo | Diario, madrugada (03:00 America/La_Paz) | cron del host / sidecar que corre `backup-db.sh` en modo `direct` |
| Copia fuera de sitio | Diario, tras el respaldo | Subir el `.dump.gz` cifrado a un bucket en otra región/cuenta |
| Rotación | En cada respaldo | La hace `backup-db.sh` (retención 14 días, mínimo 7) |
| Prueba de restauración | Mensual | `restore-db.sh` a base desechable + verificación (§5) |
| Repaso de retención/catálogo | Trimestral | Revisar `docs/security.md` §5 con Negocio/Legal |

Ejemplo de entrada cron en el host de producción (no en un contenedor efímero):

```cron
# Respaldo diario de logistics a las 03:00 (hora Bolivia)
0 3 * * *  BACKUP_MODE=direct BACKUP_DIR=/var/backups/logistics \
           DB_HOST=db.prod.interno DB_DATABASE=logistics DB_USERNAME=logistics \
           DB_PASSWORD="$(cat /run/secrets/pg_password)" \
           /opt/logistics/scripts/backup-db.sh >> /var/log/logistics-backup.log 2>&1
```

> El scheduler de Laravel (`schedule:work`) corre **dentro** del contenedor de workers y es
> efímero; el respaldo de base se agenda en el **host**/orquestador para que sobreviva a
> redeploys y no dependa de la app. Antes de un deploy con migraciones, tomar un respaldo manual
> (`backup-db.sh`) como punto de retorno.

---

## 7. Recuperación ante desastre (resumen)

1. Aprovisionar un PostgreSQL 17 + PostGIS limpio.
2. Traer el último `.dump.gz` bueno (y su `.sha256`) desde la copia fuera de sitio.
3. `BACKUP_MODE=direct ALLOW_PROD=yes DB_*=... scripts/restore-db.sh --yes <archivo>`.
4. Confirmar la verificación (tablas + PostGIS) y hacer un smoke test de la app contra la base.
5. Repuntar la app a la base restaurada y reanudar los workers/colas.
