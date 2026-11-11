#!/bin/sh
# ============================================================
# Healthcheck del sidecar pg-backup
# ============================================================
# Sano = existe `last-success` (lo escribe backup.sh SOLO tras un dump validado) y
# no tiene más de BACKUP_MAX_AGE_HOURS horas (default 26: cron diario + margen).
# Si el backup programado empieza a fallar, el contenedor pasa a `unhealthy` y se ve
# en `docker compose ps` en vez de fallar en silencio.
BACKUP_DIR="${BACKUP_DIR:-/backups}"
MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-26}"
MARKER="${BACKUP_DIR}/last-success"

if [ ! -f "$MARKER" ]; then
    echo "sin backup exitoso registrado (${MARKER} no existe)"
    exit 1
fi

last="$(sed -n 's/^epoch=//p' "$MARKER")"
case "$last" in
    ''|*[!0-9]*) echo "marcador ilegible: ${MARKER}"; exit 1 ;;
    *) ;; # epoch numérico válido: se sigue con el cálculo de antigüedad
esac

age=$(( $(date +%s) - last ))
max=$(( MAX_AGE_HOURS * 3600 ))
if [ "$age" -gt "$max" ]; then
    echo "último backup OK hace $(( age / 3600 )) h (> ${MAX_AGE_HOURS} h): $(head -n 1 "$MARKER")"
    exit 1
fi
echo "último backup OK hace $(( age / 60 )) min: $(sed -n 's/^file=//p' "$MARKER")"
exit 0
