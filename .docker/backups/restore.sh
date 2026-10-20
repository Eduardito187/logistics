#!/bin/sh
# ============================================================
# Restauración de un backup de PostgreSQL (C-1)
# ============================================================
# Restaura un dump .dump (pg_dump -Fc) en una base DESTINO. Por seguridad NO
# restaura sobre `logistics` ni `logistics_test` salvo que se pase --force:
# restaurar siempre primero a una base descartable y verificar.
#
# Uso:
#   restore.sh <archivo.dump> <base_destino> [--create] [--force]
#
#   --create  crea la base destino si no existe (CREATE DATABASE).
#   --force   permite restaurar sobre logistics / logistics_test (destructivo:
#             pg_restore --clean borra objetos previos). Úsese con MUCHO cuidado.
#
# Ejemplo (restauración de PRUEBA a una base descartable):
#   restore.sh /backups/logistics-20261020-030000.dump logistics_restore_check --create
#
# Variables de conexión: PGHOST PGPORT PGUSER PGPASSWORD (estándar libpq).
set -e

DUMP="$1"
TARGET="$2"
CREATE=0
FORCE=0
shift 2 2>/dev/null || true
for arg in "$@"; do
    case "$arg" in
        --create) CREATE=1 ;;
        --force)  FORCE=1 ;;
    esac
done

if [ -z "$DUMP" ] || [ -z "$TARGET" ]; then
    echo "Uso: restore.sh <archivo.dump> <base_destino> [--create] [--force]" >&2
    exit 2
fi
if [ ! -f "$DUMP" ]; then
    echo "[restore] ERROR: no existe el dump '${DUMP}'" >&2
    exit 2
fi

# Guardia anti-pisada de las bases reales.
if [ "$FORCE" -ne 1 ]; then
    case "$TARGET" in
        logistics|logistics_test)
            echo "[restore] BLOQUEADO: '${TARGET}' es una base real. Restaurá primero a una base descartable, o pasá --force si estás seguro." >&2
            exit 3
            ;;
    esac
fi

if [ "$CREATE" -eq 1 ]; then
    echo "[restore] Creando base '${TARGET}' (si no existe)..."
    psql -d postgres -tc "SELECT 1 FROM pg_database WHERE datname='${TARGET}'" | grep -q 1 \
        || psql -d postgres -c "CREATE DATABASE \"${TARGET}\";"
fi

echo "[restore] Restaurando '${DUMP}' → base '${TARGET}'..."
# --clean --if-exists: reemplaza objetos previos (útil al re-restaurar). --no-owner:
# no exige los roles originales. -j: restauración paralela (más rápida en dumps grandes).
pg_restore \
    --dbname="$TARGET" \
    --no-owner \
    --no-privileges \
    --clean \
    --if-exists \
    --jobs=2 \
    "$DUMP"

echo "[restore] OK. Base '${TARGET}' restaurada."
