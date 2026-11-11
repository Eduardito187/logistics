# Backups de PostgreSQL — logistics (C-1 auditoría)

> **DESACTIVADO POR DEFECTO.** El sidecar `pg-backup` está bajo el profile `backup` del
> `docker-compose.yml`: `docker compose up` NO lo arranca. El respaldo oficial es el **manual y completo
> de la base**: `php artisan backup:create` (ver `docs/backup-restore.md`). Para usar este backup
> automático en algún caso puntual, levantarlo a mano:
> `docker compose --profile backup up -d pg-backup` (y `docker compose --profile backup stop pg-backup`
> para volver a apagarlo).

Respaldo automatizado de la base `logistics`. Resuelve el riesgo #1 de la auditoría
DevOps: *"una base que crecerá a gigas sin estrategia de respaldo/restauración"*.

## Qué hace hoy (implementado y verificado)

- **Respaldo lógico diario** con `pg_dump` en formato *custom* comprimido (`-Fc`),
  servido por el sidecar `pg-backup` del `docker-compose.yml`.
- **Cron** gobernado por busybox `crond` dentro del contenedor (`BACKUP_CRON`,
  default `0 3 * * *` = 03:00 cada día) + un respaldo inicial al arrancar.
- **Retención** por días (`BACKUP_RETENTION_DAYS`, default `14`; recomendado 7–30):
  se podan automáticamente los dumps de la base más viejos que N días.
- **Volumen persistente** `backups` (montado en `/backups`). Cada dump:
  `logistics-YYYYMMDD-HHMMSS.dump` (hora local de Bolivia: el servicio fija
  `TZ=America/La_Paz`, así el nombre, el log y `BACKUP_CRON` usan la misma hora). Log en
  `/backups/backup.log` (backup inicial y los del cron).
- **Nunca deja dumps a medias**: escribe a `.dump.tmp` y solo renombra a `.dump` si el dump
  pasa la validación (`pg_restore -l` lo lee y su índice trae al menos una tabla). Un trap
  borra el `.tmp` ante fallo o interrupción, y al arrancar cada corrida se borran `.tmp`
  huérfanos (p. ej. de un `SIGKILL`). Un lock (`flock`) impide dos corridas a la vez.
- **Estado visible**: `/backups/last-success` (fecha, epoch, archivo, tamaño) se escribe
  SOLO tras un dump validado; `/backups/last-failure` registra el último fallo. Cada fallo
  deja en el log una línea `FALLO: ...`.
- **Healthcheck** (`healthcheck.sh`): el contenedor pasa a `unhealthy` si no hay
  `last-success` o si tiene más de `BACKUP_MAX_AGE_HOURS` (default 26 h). Se ve en
  `docker compose ps`; ya no se puede fallar en silencio.
- **Restauración probada** con `restore.sh` (ver abajo), con guardia anti-pisada de
  las bases reales (`logistics` / `logistics_test`).

### Por qué `pg_dump` y no PITR (todavía)

`pg_dump` es un respaldo **lógico**: restaura al momento del dump, no a un instante
arbitrario. Es robusto, portable y **verificable aquí y ahora**, por eso es lo que se
deja funcionando en este pase (la auditoría lo admite explícitamente como alternativa
al PITR si pgBackRest es demasiado para el pase).

**PITR (point-in-time recovery)** = respaldo base + archivado continuo de WAL. Es el
objetivo de producción y el siguiente paso (ver más abajo).

## Variables de entorno

| Variable | Default | Qué controla |
|---|---|---|
| `BACKUP_CRON` | `0 3 * * *` | Horario del respaldo (crontab de 5 campos). |
| `BACKUP_RETENTION_DAYS` | `14` | Días de dumps a conservar (se podan los viejos). |
| `BACKUP_MAX_AGE_HOURS` | `26` | Antigüedad máxima del último backup OK antes de marcar `unhealthy`. |

Conexión del sidecar (ya fijada en el compose): `PGHOST=postgres`, `PGPORT=5432`,
`PGUSER=logistics`, `PGPASSWORD=logistics`, `PGDATABASE=logistics`.

## Operación

```bash
# Forzar un backup YA (fuera del cron):
docker compose exec pg-backup /usr/local/bin/backup.sh

# Listar los backups existentes:
docker compose exec pg-backup ls -lh /backups

# Ver el log del cron (buscar FALLO):
docker compose exec pg-backup cat /backups/backup.log

# Estado: último backup OK / último fallo / healthcheck:
docker compose exec pg-backup cat /backups/last-success
docker compose exec pg-backup sh /usr/local/bin/healthcheck.sh
docker inspect dismac_lg_pg_backup --format '{{.State.Health.Status}}'
```

> Tras cambiar `backup.sh`, `backup-entrypoint.sh`, `healthcheck.sh` o el servicio en el
> compose, recrear SOLO este sidecar: `docker compose up -d pg-backup` (no toca la base).

### Relación con `php artisan backup:*`

Este sidecar es el respaldo **programado** de la base (dump `.dump`). Los comandos de la app
generan un **bundle** `.tar` (estructura completa + datos + `manifest.json` con conteos por
tabla), que es lo que se usa antes de operaciones de riesgo (migraciones de máquina,
despliegues):

```bash
docker compose exec -T app php artisan backup:create            # → storage/app/backups/logistics-<ts>.tar
docker compose exec -T app php artisan backup:verify <bundle>    # no destructivo (base scratch)
docker compose exec -T app php artisan backup:restore <bundle>   # swap seguro (<db>_prev_<ts>)
```

Ver `docs/backup-restore.md`. Ambos respaldan SOLO la base de datos (no contenedores,
imágenes, volúmenes ni archivos).

## Procedimiento de restauración (PROBADO)

La restauración se prueba SIEMPRE primero en una base **descartable** (nunca sobre
`logistics`). `restore.sh` lo bloquea por defecto; `--force` permite pisar las bases
reales (destructivo) solo cuando de verdad hace falta.

```bash
# 1) Elegir el dump a restaurar:
docker compose exec pg-backup ls -lh /backups

# 2) Restaurar a una base DESCARTABLE para verificar (se crea con --create):
docker compose exec pg-backup \
  /usr/local/bin/restore.sh /backups/logistics-YYYYMMDD-HHMMSS.dump logistics_restore_check --create

# 3) Verificar la base restaurada (conteos, tablas clave):
docker compose exec postgres \
  psql -U logistics -d logistics_restore_check -c "\dt" -c "SELECT count(*) FROM shipments;"

# 4) Limpiar la base de prueba cuando se termina de verificar:
docker compose exec postgres \
  psql -U logistics -d postgres -c 'DROP DATABASE logistics_restore_check;'
```

**Recuperación real ante desastre** (la base `logistics` se perdió/corrompió):

```bash
# La base debe existir (recrearla si hace falta) y estar SIN conexiones de la app.
docker compose exec pg-backup \
  /usr/local/bin/restore.sh /backups/logistics-YYYYMMDD-HHMMSS.dump logistics --force
# Luego reiniciar app/workers para que tomen conexiones limpias vía pgbouncer.
```

### Prueba ejecutada en este pase

Verificado en un PostgreSQL 17 descartable (fuera de las bases de dev):

1. Se poblaron datos de muestra en una base `logistics` de prueba.
2. `backup.sh` generó `logistics-*.dump` (pg_dump -Fc) en el volumen.
3. `restore.sh ... logistics_restore_check --create` restauró el dump a una base nueva.
4. Se confirmó que los conteos de filas de la base restaurada coinciden con el origen.
5. Se eliminó la base de prueba. Ninguna base real fue tocada.

## Producción: PITR + off-site (pendiente de habilitar)

Esto NO está implementado aquí (requiere reiniciar PostgreSQL con `archive_mode=on` y
coordinar con el almacenamiento): queda documentado como el siguiente paso.

1. **PITR con pgBackRest o wal-g**: `archive_mode=on` + `archive_command` que empuja el
   WAL a un repositorio (local y/o S3), base completa diaria + WAL continuo. Permite
   restaurar a cualquier instante. pgBackRest soporta cifrado y retención nativos.
2. **Destino OFF-SITE obligatorio**: el volumen `backups` vive en el mismo host que la
   base → NO sirve ante pérdida del host. Sincronizar a S3/MinIO (MinIO ya está en el
   stack como destino posible). Ejemplos:

   ```bash
   # Con el cliente de MinIO (mc):
   mc mirror --overwrite /backups myminio/logistics-backups
   # Con AWS CLI contra S3/MinIO:
   aws s3 sync /backups s3://logistics-backups/ --endpoint-url "$AWS_ENDPOINT"
   ```

   Idealmente el `backup.sh` encadena el `sync` off-site tras cada dump, y el bucket
   tiene versionado + lifecycle. **Verificar cifrado en reposo y en tránsito.**
3. **Probar la restauración periódicamente** (un backup no probado no es un backup):
   automatizar una restauración mensual a una base descartable como check.
