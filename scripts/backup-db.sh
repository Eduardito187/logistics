#!/usr/bin/env bash
# ============================================================
# backup-db.sh — respaldo de PostgreSQL + PostGIS (logistics)
# ============================================================
# Genera un dump en formato "custom" de pg_dump (`-Fc`), apto para PostGIS, y lo
# comprime con gzip. Pensado para correr DESDE EL HOST:
#
#   - modo `container` (default): hace `docker exec` contra el contenedor de
#     Postgres del docker-compose (dismac_lg_postgres). Útil en desarrollo/staging.
#   - modo `direct`: corre pg_dump instalado en el host contra un Postgres remoto
#     (DB gestionada en producción). No necesita Docker.
#
# El formato custom (no plano) se eligió a propósito:
#   - restaura con pg_restore (selectivo, en paralelo con -j, reordena dependencias),
#   - trae las extensiones (PostGIS) y el orden correcto de objetos,
#   - se comprime bien. Aquí pg_dump escribe SIN comprimir (`-Z0`) y gzip hace la
#     compresión externa, para dejar un `.dump.gz` estándar y verificable con gunzip.
#
# Configuración por variables de entorno (ver también docs/backup-restore.md):
#   BACKUP_MODE            container | direct            (default: container)
#   PG_CONTAINER           nombre del contenedor          (default: dismac_lg_postgres)
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   (conexión)
#   BACKUP_DIR             carpeta destino                (default: ./backups)
#   BACKUP_RETENTION_DAYS  borra respaldos más viejos     (default: 14)
#   BACKUP_RETENTION_MIN   conserva al menos N respaldos  (default: 7)
#   GZIP_LEVEL             nivel de compresión gzip 1..9  (default: 6)
#
# Uso:
#   scripts/backup-db.sh
#   BACKUP_MODE=direct DB_HOST=db.prod DB_PASSWORD=... scripts/backup-db.sh
#
# Salida: $BACKUP_DIR/logistics-YYYYmmdd-HHMMSS.dump.gz (+ .sha256)
# Código de salida 0 = respaldo verificado; distinto de 0 = falló (no rota nada).
# ============================================================
set -euo pipefail

# -------------------- Configuración --------------------
BACKUP_MODE="${BACKUP_MODE:-container}"
PG_CONTAINER="${PG_CONTAINER:-dismac_lg_postgres}"

DB_HOST="${DB_HOST:-127.0.0.1}"
# En modo container la conexión es interna al contenedor: puerto 5432, no el publicado.
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-logistics}"
DB_USERNAME="${DB_USERNAME:-logistics}"
DB_PASSWORD="${DB_PASSWORD:-logistics}"

BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
BACKUP_RETENTION_MIN="${BACKUP_RETENTION_MIN:-7}"
GZIP_LEVEL="${GZIP_LEVEL:-6}"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
BASENAME="logistics-${TIMESTAMP}"
OUTFILE="${BACKUP_DIR}/${BASENAME}.dump.gz"
TMPFILE="${OUTFILE}.partial"

log() { printf '[backup-db] %s\n' "$*" >&2; }
die() { printf '[backup-db] ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() { rm -f "$TMPFILE" 2>/dev/null || true; }
trap cleanup EXIT

# -------------------- Ejecutores por modo --------------------
# pg_run: corre un binario de Postgres (pg_dump/pg_restore/psql) en el entorno
# correcto según BACKUP_MODE. PGPASSWORD viaja como variable de entorno, nunca en
# la línea de comandos (no queda en `ps` ni en el historial).
pg_run() {
    if [[ "$BACKUP_MODE" == "container" ]]; then
        docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PG_CONTAINER" "$@"
    else
        PGPASSWORD="$DB_PASSWORD" "$@"
    fi
}

preflight() {
    if [[ "$BACKUP_MODE" == "container" ]]; then
        command -v docker >/dev/null 2>&1 || die "docker no está instalado (BACKUP_MODE=container)"
        docker inspect -f '{{.State.Running}}' "$PG_CONTAINER" 2>/dev/null | grep -q true \
            || die "el contenedor '$PG_CONTAINER' no está corriendo"
    elif [[ "$BACKUP_MODE" == "direct" ]]; then
        command -v pg_dump >/dev/null 2>&1 || die "pg_dump no está en el PATH (BACKUP_MODE=direct)"
        command -v gzip >/dev/null 2>&1 || die "gzip no está en el PATH"
    else
        die "BACKUP_MODE inválido: '$BACKUP_MODE' (usar container | direct)"
    fi
}

# -------------------- Respaldo --------------------
main() {
    preflight
    mkdir -p "$BACKUP_DIR"

    log "respaldando base '${DB_DATABASE}' (modo ${BACKUP_MODE}) → ${OUTFILE}"

    # pg_dump en formato custom, sin compresión interna; gzip comprime el flujo.
    # --no-owner / --no-privileges: el restore recrea objetos bajo el rol que restaura,
    # sin depender de roles del origen (clave al mover entre entornos).
    if ! pg_run pg_dump \
            --host="$DB_HOST" \
            --port="$DB_PORT" \
            --username="$DB_USERNAME" \
            --dbname="$DB_DATABASE" \
            --format=custom \
            --compress=0 \
            --no-owner \
            --no-privileges \
            --verbose \
        | gzip -"${GZIP_LEVEL}" > "$TMPFILE"; then
        die "pg_dump falló; no se deja respaldo parcial"
    fi

    # Verificación mínima: el gzip debe estar íntegro y el archivo custom legible.
    gzip -t "$TMPFILE" || die "el respaldo gzip está corrupto"
    if [[ ! -s "$TMPFILE" ]]; then
        die "el respaldo quedó vacío"
    fi

    mv "$TMPFILE" "$OUTFILE"
    ( cd "$BACKUP_DIR" && sha256sum "${BASENAME}.dump.gz" > "${BASENAME}.dump.gz.sha256" )

    local size
    size="$(du -h "$OUTFILE" | cut -f1)"
    log "respaldo OK (${size}): ${OUTFILE}"

    rotate
    log "listo."
}

# -------------------- Rotación --------------------
# Borra respaldos más viejos que BACKUP_RETENTION_DAYS, pero conserva siempre al
# menos BACKUP_RETENTION_MIN (red de seguridad si un día no hubo respaldos nuevos).
rotate() {
    log "rotación: retención ${BACKUP_RETENTION_DAYS} días, mínimo ${BACKUP_RETENTION_MIN} respaldos"

    local -a all=()
    while IFS= read -r f; do
        all+=("$f")
    done < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'logistics-*.dump.gz' | sort)

    local total=${#all[@]}
    if (( total <= BACKUP_RETENTION_MIN )); then
        log "hay ${total} respaldos (<= mínimo ${BACKUP_RETENTION_MIN}); no se borra nada"
        return 0
    fi

    # Cuántos se pueden borrar como máximo sin bajar del mínimo.
    local deletable=$(( total - BACKUP_RETENTION_MIN ))
    local removed=0
    local f
    for f in "${all[@]}"; do
        (( removed >= deletable )) && break
        # ¿Más viejo que la retención? find -mtime cuenta en días completos.
        if find "$f" -mtime "+${BACKUP_RETENTION_DAYS}" | grep -q .; then
            rm -f "$f" "${f}.sha256"
            log "borrado por antigüedad: $(basename "$f")"
            removed=$(( removed + 1 ))
        fi
    done
    log "rotación: ${removed} respaldo(s) borrado(s)"
}

main "$@"
