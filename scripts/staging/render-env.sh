#!/usr/bin/env bash
# ============================================================
# render-env.sh — genera el env-file de STAGING desde los secrets del repo
# ============================================================
# Uso:  scripts/staging/render-env.sh <archivo-destino>
#
# Lee de variables de entorno (en el workflow vienen de `secrets`/`vars` de GitHub):
#   APP_KEY            (obligatorio)  clave de Laravel, formato `base64:...`
#   DB_PASSWORD        (obligatorio)  contraseña de PostgreSQL de staging
#   APP_URL            (opcional)     URL pública; default http://localhost:<puerto>
#   STAGING_HTTP_PORT  (opcional)     puerto publicado por nginx; default 8110
#   STAGING_ENV_EXTRA  (opcional)     líneas KEY=VALUE adicionales (SAP, FCM, S3, MAIL, LABELS…).
#                                     PISAN a las de la base: una clave definida acá reemplaza a la
#                                     de abajo (se filtra de la base, no se duplica).
#
# El archivo sale con permisos 600 y NUNCA se imprime (solo se listan las CLAVES, sin valores).
# Idempotente: misma entrada → mismo archivo.
# ============================================================
set -euo pipefail

out="${1:?Uso: render-env.sh <archivo-destino>}"

fail() { echo "::error::$*" >&2; exit 1; }

[[ -n "${APP_KEY:-}" ]] || fail "Falta el secret STAGING_APP_KEY (generalo con: php artisan key:generate --show)."
[[ "${APP_KEY}" == base64:* ]] || fail "STAGING_APP_KEY debe tener formato 'base64:...'."
[[ -n "${DB_PASSWORD:-}" ]] || fail "Falta el secret STAGING_DB_PASSWORD."

port="${STAGING_HTTP_PORT:-8110}"
[[ "$port" =~ ^[0-9]+$ ]] || fail "STAGING_HTTP_PORT debe ser numérico (vino '$port')."
app_url="${APP_URL:-http://localhost:${port}}"

umask 077
mkdir -p "$(dirname "$out")"

base="$(mktemp)"
extra="$(mktemp)"
trap 'rm -f "$base" "$extra"' EXIT

# Base de staging: perfil "producción" (caches en el arranque, sin debug), pero con integraciones
# externas en modo seguro por defecto (mail a log, broadcasting a log, disco local).
cat > "$base" <<EOF
APP_NAME=Logistics Staging
APP_ENV=staging
APP_DEBUG=false
APP_KEY=${APP_KEY}
APP_URL=${app_url}
APP_TIMEZONE=America/La_Paz
APP_LOCALE=es
APP_FALLBACK_LOCALE=en

OPTIMIZE_ON_BOOT=true
AUTO_MIGRATE=false
COMPOSER_INSTALL_ON_BOOT=false
# Una credencial de base mala falla en 3 min (con mensaje) en vez de colgar el deploy.
DB_WAIT_TIMEOUT=180

LOG_CHANNEL=stack
LOG_STACK=json
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=pgbouncer
DB_PORT=6432
DB_DIRECT_HOST=postgres
DB_DIRECT_PORT=5432
DB_DATABASE=logistics
DB_USERNAME=logistics
DB_PASSWORD=${DB_PASSWORD}

REDIS_CLIENT=phpredis
REDIS_HOST=valkey
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER=log
EOF

# STAGING_ENV_EXTRA: normaliza CRLF y descarta comentarios/líneas vacías.
printf '%s\n' "${STAGING_ENV_EXTRA:-}" | tr -d '\r' | grep -E '^[A-Za-z_][A-Za-z0-9_]*=' > "$extra" || true

# Claves que vienen en EXTRA → se quitan de la base para que EXTRA mande (sin claves duplicadas).
if [[ -s "$extra" ]]; then
    awk -F= 'NR==FNR { override[$1]=1; next } !($1 in override)' "$extra" "$base" > "$out"
    cat "$extra" >> "$out"
else
    cp "$base" "$out"
fi

# STAGING_HTTP_PORT también lo interpola el compose (puerto publicado de nginx).
grep -q '^STAGING_HTTP_PORT=' "$out" || echo "STAGING_HTTP_PORT=${port}" >> "$out"

chmod 600 "$out"
echo "[render-env] env de staging generado ($(grep -c '=' "$out") claves):"
cut -d= -f1 "$out" | grep -v '^$' | sort | tr '\n' ' '
echo
