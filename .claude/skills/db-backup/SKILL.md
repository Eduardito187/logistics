---
name: db-backup
description: >-
  Respaldar, verificar o restaurar la base PostgreSQL de la plataforma logística (Dismac) con el backup
  MANUAL y completo (estructura + datos): backup:create / backup:verify / backup:restore. Usar cuando se
  pida "backup", "respaldo", "restaurar la base", "migrar datos", "verificar un dump", antes de una
  migración riesgosa o de mover el proyecto de máquina, o cuando un restore falle.
---

# Backup de la base (manual, completo, verificable)

Decisión del dueño: el backup es **solo la base PostgreSQL** (estructura completa + todos los datos), y
**solo manual**. Nada de archivos subidos, volúmenes, contenedores ni imágenes. El sidecar automático
`pg-backup` está **desactivado** (profile `backup`): no proponerlo ni levantarlo. Doc completa:
`docs/backup-restore.md`.

## Reglas duras

1. **Nunca** `migrate:fresh`, `db:wipe`, `TRUNCATE`, `DROP` sobre `logistics` para "probar" un backup.
2. Para probar un restore usar un **Postgres descartable**, nunca la base de dev:
   ```bash
   docker run -d --name lg-tmp-pg -e POSTGRES_USER=logistics -e POSTGRES_PASSWORD=tmp -e POSTGRES_DB=logistics postgis/postgis:17-3.5-alpine
   # … comandos con --host/--port apuntando a ese contenedor (red: --network container:lg-tmp-pg) …
   docker rm -f lg-tmp-pg
   ```
3. `backup:restore` sobre una base viva: antes `php artisan down` y parar workers (una escritura durante
   el swap hace fallar la verificación final aunque el swap ya se haya hecho).
4. En Git Bash, comandos docker con rutas `/…` llevan `MSYS_NO_PATHCONV=1`.

## Comandos (dentro del contenedor `app`)

```bash
docker compose exec -T app php artisan backup:create                 # → storage/app/backups/logistics-<YYYYmmdd-HHMMSS>.tar
docker compose exec -T app php artisan backup:verify <bundle.tar>    # NO destructivo: scratch DB + comparación + drop
docker compose exec -T app php artisan backup:restore <bundle.tar>   # restore seguro por swap (pide confirmación)
```

- Bundle `.tar` = `database.dump` (pg_dump -Fc) + `manifest.json` (formato 1: versiones, sha256,
  inventario de estructura, `database.row_counts` exactos, todo del mismo snapshot).
- `backup:verify` restaura en `<db>_verify_<ts>` y la dropea SIEMPRE. Exit ≠0 con tabla de diferencias.
- `backup:restore`: restaura en `<db>_restore_<ts>` → verifica (si no cuadra aborta, la viva intacta) →
  swap (viva → `<db>_prev_<ts>`, nueva → `<db>`) → verificación final. Imprime cómo volver atrás.
- `db:backup` / `db:restore` (legado): `.dump` + `<file>.manifest.json`; mismo camino seguro.
- Conecta **directo** a Postgres (`DB_DIRECT_HOST`/`DB_DIRECT_PORT`), no por PgBouncer.

## Diagnóstico rápido

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `cannot drop extension postgis` | restore viejo con `--clean` sobre base de la imagen postgis | usar `backup:restore`/`db:restore` actuales (no usan `--clean`) |
| `pg_dump X es más viejo que el servidor` | cliente pg < server | imagen con `postgresql-client` ≥ major del server (dev/prod traen 18.6) |
| `pg_restore: unsupported version (1.16)` | dump de pg_dump 18 leído con pg_restore 17 | restaurar con los comandos de la app, no con el contenedor postgis |
| Verify falla por conteos | el dump no es el que dice el manifest, o datos alterados | no restaurar ese bundle; generar uno nuevo |
| Quedan bases `_verify_`/`_restore_` | corrida interrumpida | `SELECT datname FROM pg_database WHERE datname ~ '_(verify|restore)_'` y dropearlas (solo esas) |

## Verificación (reportar números)

Tras `backup:create`: tablas, índices, constraints, secuencias, extensiones, filas y tamaño. Tras
`backup:verify`: "estructura y conteo de filas idénticos" y 0 bases auxiliares residuales:
```bash
docker compose exec -T postgres psql -U logistics -d postgres -Atc "select count(*) from pg_database where datname ~ '_(verify|restore)_'"
```

Relacionado: memorias [[db-backup-restore]], [[backup-manual-only]], [[no-destructive-db-ops]].
