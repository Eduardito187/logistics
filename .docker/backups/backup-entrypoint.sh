#!/bin/sh
# ============================================================
# Entrypoint del sidecar de backups de PostgreSQL (C-1)
# ============================================================
# Instala el crontab (busybox crond) a partir de BACKUP_CRON y deja el proceso
# en foreground. Corre un backup inicial al arrancar para que el volumen nunca
# quede sin un respaldo reciente (p.ej. tras un `up` en una máquina nueva).
#
# Todo (backup inicial y los del cron) se registra en ${BACKUP_DIR}/backup.log y
# también en la salida del contenedor (`docker compose logs pg-backup`) para el
# inicial. El estado se ve en `docker compose ps` gracias a healthcheck.sh.
set -e

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_CRON="${BACKUP_CRON:-0 3 * * *}"
LOG="${BACKUP_DIR}/backup.log"

mkdir -p "$BACKUP_DIR"

echo "[pg-backup] Zona horaria: ${TZ:-UTC} ($(date -Iseconds))"
echo "[pg-backup] Programando backup con cron: '${BACKUP_CRON}' (hora local del contenedor)"
echo "[pg-backup] Retención: ${BACKUP_RETENTION_DAYS:-14} días | destino: ${BACKUP_DIR} | alerta si el último OK supera ${BACKUP_MAX_AGE_HOURS:-26} h"

# Crontab de busybox: el entorno de cron NO hereda las variables del contenedor,
# por eso se exportan explícitamente dentro de la línea del job (TZ incluida, así el
# nombre del archivo y los logs del cron usan la misma hora local que el resto).
CRON_ENV="TZ='${TZ}' PGHOST='${PGHOST}' PGPORT='${PGPORT}' PGUSER='${PGUSER}' PGPASSWORD='${PGPASSWORD}' PGDATABASE='${PGDATABASE}' BACKUP_DIR='${BACKUP_DIR}' BACKUP_RETENTION_DAYS='${BACKUP_RETENTION_DAYS}'"
mkdir -p /etc/crontabs
echo "${BACKUP_CRON} ${CRON_ENV} /usr/local/bin/backup.sh >> ${LOG} 2>&1" > /etc/crontabs/root

# Backup inicial (no bloquea el arranque si falla: el cron reintentará y el
# healthcheck lo marca unhealthy si no hay un backup OK reciente). Se guarda en el
# log del volumen Y en la salida del contenedor.
set +e
/usr/local/bin/backup.sh > /tmp/backup-initial.log 2>&1
rc=$?
set -e
cat /tmp/backup-initial.log
cat /tmp/backup-initial.log >> "$LOG"
if [ "$rc" -ne 0 ]; then
    echo "[pg-backup] AVISO: el backup inicial falló (código ${rc}); el cron reintentará."
fi

echo "[pg-backup] Arrancando crond en foreground."
exec crond -f -l 8
