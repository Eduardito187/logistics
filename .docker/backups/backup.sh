#!/bin/sh
# ============================================================
# Backup de PostgreSQL — pg_dump formato custom comprimido (C-1)
# ============================================================
# Genera un respaldo LÓGICO de la base (-Fc = custom, comprimido, restaurable con
# pg_restore de forma selectiva y paralela), lo VALIDA antes de darlo por bueno y
# poda los más viejos que la retención.
#
# Garantías (corrigen los `.dump.tmp` de 0 bytes que quedaban sin aviso):
#   - Se escribe a `<archivo>.dump.tmp` y SOLO se renombra a `.dump` si el dump pasó
#     la validación (`pg_restore -l` lo lee y su índice trae al menos una TABLE).
#   - Un trap borra el `.tmp` ante cualquier fallo o interrupción (INT/TERM/HUP):
#     un dump a medias nunca queda en el volumen ni parece válido.
#   - Al arrancar se limpian `.tmp` huérfanos de corridas viejas (p. ej. un SIGKILL,
#     que no se puede atrapar). Un lock (flock) impide dos corridas simultáneas, así la
#     limpieza nunca borra el `.tmp` de una corrida en curso.
#   - Éxito → `last-success` (fecha legible + epoch + archivo). Fallo → `last-failure`.
#     El healthcheck del servicio (healthcheck.sh) mira `last-success`.
#   - Código de salida 0 SOLO si hay un dump nuevo validado.
#
# Variables (las fija el servicio pg-backup del compose o el cron):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE  → conexión (estándar libpq)
#   BACKUP_DIR              → carpeta destino (volumen `backups`)
#   BACKUP_RETENTION_DAYS   → días a conservar
#   TZ                      → zona horaria del nombre del archivo y de los logs
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
DB="${PGDATABASE:-logistics}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${BACKUP_DIR}/${DB}-${STAMP}.dump"
TMP="${OUT}.tmp"
LOCK="${BACKUP_DIR}/.backup.lock"
SUCCESS_MARKER="${BACKUP_DIR}/last-success"
FAILURE_MARKER="${BACKUP_DIR}/last-failure"
DONE=0
LOCKED=0

log() { echo "[pg-backup] $(date -Iseconds) $*"; }

# Se ejecuta SIEMPRE al salir: si no se llegó al final con éxito, borra el .tmp y deja
# constancia del fallo (marcador + log con la palabra FALLO, fácil de buscar).
on_exit() {
    code=$?
    # Sin el lock no se creó nada propio: no se borra ni se marca nada (el .tmp que
    # pudiera existir es de la corrida que SÍ tiene el lock).
    if [ "$LOCKED" -ne 1 ]; then
        exit "$code"
    fi
    if [ "$DONE" -ne 1 ]; then
        rm -f "$TMP"
        [ "$code" -eq 0 ] && code=1
        log "FALLO: el backup de '${DB}' NO se generó (código ${code}). No quedó ningún archivo parcial."
        printf '%s\nepoch=%s\ncode=%s\n' "$(date -Iseconds)" "$(date +%s)" "$code" > "$FAILURE_MARKER" 2>/dev/null || true
    fi
    exit "$code"
}
trap on_exit EXIT
# Señales: salir con error dispara el trap EXIT (que limpia). 128+N por convención.
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$BACKUP_DIR"

# Un solo backup a la vez (cron + backup manual + backup inicial podrían cruzarse).
exec 9>"$LOCK"
if ! flock -n 9; then
    log "Otro backup está en curso (lock ${LOCK}); se aborta esta corrida."
    exit 75
fi
LOCKED=1

# Con el lock tomado, cualquier .tmp que exista es de una corrida muerta: se borra.
for orphan in "$BACKUP_DIR"/*.dump.tmp; do
    [ -e "$orphan" ] || continue
    log "Borrando .tmp huérfano de una corrida anterior: $(basename "$orphan")"
    rm -f "$orphan"
done

log "Iniciando pg_dump de '${DB}' en ${PGHOST:-localhost}:${PGPORT:-5432} → ${OUT}"

# -Fc: formato custom comprimido. --no-owner/--no-privileges: la restauración no
# depende de roles exactos (portable entre entornos).
pg_dump \
    --format=custom \
    --compress=6 \
    --no-owner \
    --no-privileges \
    --file="$TMP" \
    "$DB"

# Validación ANTES de renombrar: el archivo existe, no está vacío, pg_restore puede
# leer su índice (TOC) y el índice trae al menos una tabla.
if [ ! -s "$TMP" ]; then
    log "El dump quedó vacío (0 bytes)."
    exit 1
fi
TOC="$(pg_restore --list "$TMP")" || { log "pg_restore -l no pudo leer el dump (corrupto)."; exit 1; }
TABLES="$(printf '%s\n' "$TOC" | grep -c ' TABLE ' || true)"
DATA="$(printf '%s\n' "$TOC" | grep -c ' TABLE DATA ' || true)"
if [ "${TABLES:-0}" -eq 0 ]; then
    log "El índice del dump no contiene ninguna tabla: se descarta."
    exit 1
fi

mv "$TMP" "$OUT"
DONE=1

SIZE="$(du -h "$OUT" | cut -f1)"
log "OK: ${OUT} (${SIZE}; ${TABLES} entradas TABLE, ${DATA} con datos)"
printf '%s\nepoch=%s\nfile=%s\nsize=%s\n' "$(date -Iseconds)" "$(date +%s)" "$(basename "$OUT")" "$SIZE" > "$SUCCESS_MARKER"

# Poda por retención: borra SOLO los dumps de esta base más viejos que N días.
# No toca ningún otro archivo del volumen. Un fallo acá no invalida el backup ya hecho.
log "Podando dumps de '${DB}' con más de ${RETENTION} días..."
find "$BACKUP_DIR" -maxdepth 1 -name "${DB}-*.dump" -type f -mtime "+${RETENTION}" -print -delete || true

echo "[pg-backup] Backups actuales:"
ls -lh "$BACKUP_DIR"/"${DB}"-*.dump 2>/dev/null || echo "  (ninguno)"
