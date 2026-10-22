#!/bin/sh
# ============================================================
# Entrypoint del sidecar de backups de PostgreSQL (C-1)
# ============================================================
# Instala el crontab (busybox crond) a partir de BACKUP_CRON y deja el proceso
# en foreground. Corre un backup inicial al arrancar para que el volumen nunca
# quede sin un respaldo reciente (p.ej. tras un `up` en una máquina nueva).
set -e

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_CRON="${BACKUP_CRON:-0 3 * * *}"

mkdir -p "$BACKUP_DIR"

echo "[pg-backup] Programando backup con cron: '${BACKUP_CRON}'"
echo "[pg-backup] Retención: ${BACKUP_RETENTION_DAYS:-14} días | destino: ${BACKUP_DIR}"

# Crontab de busybox: el entorno de cron NO hereda las variables del contenedor,
# por eso se exportan explícitamente dentro de la línea del job.
CRON_ENV="PGHOST='${PGHOST}' PGPORT='${PGPORT}' PGUSER='${PGUSER}' PGPASSWORD='${PGPASSWORD}' PGDATABASE='${PGDATABASE}' BACKUP_DIR='${BACKUP_DIR}' BACKUP_RETENTION_DAYS='${BACKUP_RETENTION_DAYS}'"
mkdir -p /etc/crontabs
echo "${BACKUP_CRON} ${CRON_ENV} /usr/local/bin/backup.sh >> ${BACKUP_DIR}/backup.log 2>&1" > /etc/crontabs/root

# Backup inicial (no bloquea el arranque si falla: el cron reintentará).
/usr/local/bin/backup.sh || echo "[pg-backup] AVISO: el backup inicial falló; el cron reintentará."

echo "[pg-backup] Arrancando crond en foreground."
exec crond -f -l 8
