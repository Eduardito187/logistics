#!/bin/sh
# ============================================================
# Backup de PostgreSQL — pg_dump formato custom comprimido (C-1)
# ============================================================
# Genera un respaldo LÓGICO de la base (-Fc = custom, comprimido, restaurable con
# pg_restore de forma selectiva y paralela) y poda los más viejos que la retención.
#
# Variables (las fija el servicio pg-backup del compose o el cron):
#   PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE  → conexión (estándar libpq)
#   BACKUP_DIR              → carpeta destino (volumen `backups`)
#   BACKUP_RETENTION_DAYS   → días a conservar
set -e

BACKUP_DIR="${BACKUP_DIR:-/backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
DB="${PGDATABASE:-logistics}"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="${BACKUP_DIR}/${DB}-${STAMP}.dump"

mkdir -p "$BACKUP_DIR"

echo "[pg-backup] $(date -Iseconds) Iniciando pg_dump de '${DB}' → ${OUT}"

# -Fc: formato custom comprimido. --no-owner/--no-privileges: la restauración no
# depende de roles exactos (portable entre entornos). Escribe a un .tmp y renombra
# al final para que un dump a medias nunca parezca válido (atomicidad).
pg_dump \
    --format=custom \
    --compress=6 \
    --no-owner \
    --no-privileges \
    --file="${OUT}.tmp" \
    "$DB"

mv "${OUT}.tmp" "$OUT"

SIZE="$(du -h "$OUT" | cut -f1)"
echo "[pg-backup] $(date -Iseconds) OK: ${OUT} (${SIZE})"

# Poda por retención: borra SOLO los dumps de esta base más viejos que N días.
# No toca ningún otro archivo del volumen.
echo "[pg-backup] Podando dumps de '${DB}' con más de ${RETENTION} días..."
find "$BACKUP_DIR" -maxdepth 1 -name "${DB}-*.dump" -type f -mtime "+${RETENTION}" -print -delete || true

echo "[pg-backup] Backups actuales:"
ls -lh "$BACKUP_DIR"/${DB}-*.dump 2>/dev/null || echo "  (ninguno)"
