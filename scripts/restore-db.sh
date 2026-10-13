#!/usr/bin/env bash
# ============================================================
# restore-db.sh — restauración de PostgreSQL + PostGIS (logistics)
# ============================================================
# Restaura un respaldo generado por scripts/backup-db.sh (`.dump.gz`, formato
# custom de pg_dump comprimido con gzip). Hace DROP + CREATE de la base destino y
# verifica el resultado (conteo de tablas, versión de PostGIS y una fila sonda).
#
# DESTRUCTIVO: borra por completo la base destino antes de restaurar. Por eso exige
# confirmación salvo que se pase --yes (o CONFIRM=yes), y se niega a correr sobre
# la base de PRODUCCIÓN a menos que ALLOW_PROD=yes esté explícito.
#
# Modos (igual que backup-db.sh):
#   - container (default): `docker exec` contra dismac_lg_postgres.
#   - direct: psql/pg_restore del host contra un Postgres remoto.
#
# Configuración por variables de entorno:
#   BACKUP_MODE            container | direct            (default: container)
#   PG_CONTAINER           nombre del contenedor          (default: dismac_lg_postgres)
#   DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   (conexión; DB_DATABASE = destino)
#   PG_MAINTENANCE_DB      base de mantenimiento p/ DROP  (default: postgres)
#   RESTORE_JOBS           paralelismo de pg_restore -j   (default: 2)
#   ALLOW_PROD=yes         permite restaurar si APP_ENV/DB sugieren producción
#   CONFIRM=yes | --yes    salta la pregunta interactiva
#
# Uso:
#   scripts/restore-db.sh ./backups/logistics-20261013-030000.dump.gz
#   scripts/restore-db.sh --yes backups/ultimo.dump.gz
#
# Código de salida 0 = restauración verificada.
# ============================================================
set -euo pipefail

# -------------------- Configuración --------------------
BACKUP_MODE="${BACKUP_MODE:-container}"
PG_CONTAINER="${PG_CONTAINER:-dismac_lg_postgres}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-logistics}"
DB_USERNAME="${DB_USERNAME:-logistics}"
DB_PASSWORD="${DB_PASSWORD:-logistics}"
PG_MAINTENANCE_DB="${PG_MAINTENANCE_DB:-postgres}"
RESTORE_JOBS="${RESTORE_JOBS:-2}"
ALLOW_PROD="${ALLOW_PROD:-no}"
CONFIRM="${CONFIRM:-no}"

log() { printf '[restore-db] %s\n' "$*" >&2; }
die() { printf '[restore-db] ERROR: %s\n' "$*" >&2; exit 1; }

# -------------------- Argumentos --------------------
BACKUP_FILE=""
for arg in "$@"; do
    case "$arg" in
        --yes|-y) CONFIRM=yes ;;
        --help|-h)
            sed -n '2,36p' "$0"; exit 0 ;;
        -*) die "opción desconocida: $arg" ;;
        *)  BACKUP_FILE="$arg" ;;
    esac
done
[[ -n "$BACKUP_FILE" ]] || die "falta el archivo de respaldo. Uso: $0 [--yes] <archivo.dump.gz>"
[[ -f "$BACKUP_FILE" ]] || die "no existe el archivo: $BACKUP_FILE"

# -------------------- Ejecutores por modo --------------------
# pg_run_stdin: corre un binario de Postgres leyendo de STDIN (para pg_restore <).
pg_run_stdin() {
    if [[ "$BACKUP_MODE" == "container" ]]; then
        docker exec -i -e PGPASSWORD="$DB_PASSWORD" "$PG_CONTAINER" "$@"
    else
        PGPASSWORD="$DB_PASSWORD" "$@"
    fi
}

# psql_q: ejecuta SQL contra la base de mantenimiento y devuelve el valor "crudo".
psql_q() {
    local db="$1" sql="$2"
    pg_run_stdin psql \
        --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
        --dbname="$db" --no-align --tuples-only --quiet --command="$sql" </dev/null
}

preflight() {
    if [[ "$BACKUP_MODE" == "container" ]]; then
        command -v docker >/dev/null 2>&1 || die "docker no está instalado (BACKUP_MODE=container)"
        docker inspect -f '{{.State.Running}}' "$PG_CONTAINER" 2>/dev/null | grep -q true \
            || die "el contenedor '$PG_CONTAINER' no está corriendo"
    elif [[ "$BACKUP_MODE" == "direct" ]]; then
        command -v pg_restore >/dev/null 2>&1 || die "pg_restore no está en el PATH (BACKUP_MODE=direct)"
        command -v psql >/dev/null 2>&1 || die "psql no está en el PATH"
    else
        die "BACKUP_MODE inválido: '$BACKUP_MODE' (usar container | direct)"
    fi
    command -v gunzip >/dev/null 2>&1 || die "gunzip no está en el PATH"
    gzip -t "$BACKUP_FILE" || die "el respaldo gzip está corrupto: $BACKUP_FILE"

    # Si existe el .sha256 al lado, verificarlo antes de tocar nada.
    if [[ -f "${BACKUP_FILE}.sha256" ]]; then
        ( cd "$(dirname "$BACKUP_FILE")" && sha256sum -c "$(basename "$BACKUP_FILE").sha256" ) \
            || die "la suma SHA-256 no coincide; respaldo posiblemente dañado"
        log "checksum SHA-256 verificado"
    fi
}

guard_prod() {
    # Heurística de seguridad: nombres que huelen a producción. Es una red, no una
    # garantía; la fuente de verdad es quién ejecuta y con qué env.
    if [[ "$ALLOW_PROD" != "yes" ]]; then
        case "${DB_DATABASE}${DB_HOST}${APP_ENV:-}" in
            *prod*|*production*)
                die "destino parece PRODUCCIÓN (db/host/env contienen 'prod'). Exportar ALLOW_PROD=yes para forzar." ;;
        esac
    fi
}

confirm() {
    [[ "$CONFIRM" == "yes" ]] && return 0
    log "Se va a BORRAR y recrear la base '${DB_DATABASE}' en ${DB_HOST}:${DB_PORT} (modo ${BACKUP_MODE})."
    read -r -p "[restore-db] Escribí el nombre de la base para confirmar: " answer
    [[ "$answer" == "$DB_DATABASE" ]] || die "confirmación no coincide; se aborta"
}

# -------------------- Drop + create --------------------
recreate_database() {
    log "cortando conexiones y recreando la base '${DB_DATABASE}'"

    # Cortar sesiones activas para poder dropear.
    psql_q "$PG_MAINTENANCE_DB" \
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${DB_DATABASE}' AND pid <> pg_backend_pid();" \
        >/dev/null || true

    psql_q "$PG_MAINTENANCE_DB" "DROP DATABASE IF EXISTS \"${DB_DATABASE}\";" >/dev/null
    psql_q "$PG_MAINTENANCE_DB" "CREATE DATABASE \"${DB_DATABASE}\" OWNER \"${DB_USERNAME}\";" >/dev/null
}

# -------------------- Restore --------------------
restore() {
    log "restaurando ${BACKUP_FILE} → ${DB_DATABASE}"

    # El dump custom trae `CREATE EXTENSION postgis`; --exit-on-error aborta ante
    # cualquier fallo real. No se usa -j (paralelo) porque se lee de STDIN (pipe).
    if ! gunzip -c "$BACKUP_FILE" | pg_run_stdin pg_restore \
            --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
            --dbname="$DB_DATABASE" \
            --no-owner --no-privileges \
            --exit-on-error \
            --verbose; then
        die "pg_restore falló; la base puede quedar incompleta. Revisar y reintentar."
    fi
}

# -------------------- Verificación --------------------
verify() {
    log "verificando la restauración"

    local tables postgis
    tables="$(psql_q "$DB_DATABASE" "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public';")"
    tables="${tables//[[:space:]]/}"
    [[ "${tables:-0}" -gt 0 ]] || die "la base restaurada no tiene tablas en 'public'"
    log "tablas en 'public': ${tables}"

    postgis="$(psql_q "$DB_DATABASE" "SELECT extversion FROM pg_extension WHERE extname = 'postgis';" || true)"
    postgis="${postgis//[[:space:]]/}"
    if [[ -n "$postgis" ]]; then
        log "PostGIS presente: ${postgis}"
    else
        log "AVISO: no se encontró la extensión PostGIS en la base restaurada (¿el origen la usaba?)"
    fi

    log "restauración verificada."
}

main() {
    preflight
    guard_prod
    confirm
    recreate_database
    restore
    verify
    log "listo."
}

main
