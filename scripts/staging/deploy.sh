#!/usr/bin/env bash
# ============================================================
# deploy.sh — build + deploy + smoke test de STAGING (proyecto compose `logistics-staging`)
# ============================================================
# Uso:  scripts/staging/deploy.sh <env-file>        # deploy completo
#       scripts/staging/deploy.sh <env-file> build  # solo construir imágenes (validación de PR)
#
# Variables opcionales:
#   STAGING_PROJECT    nombre del proyecto compose (default logistics-staging; FIJO en el workflow)
#   STAGING_IMAGE_TAG  tag de las imágenes (default staging; los PR usan pr-<n> para no pisarlas)
#
# Idempotente y apto para la PRIMERA corrida: si no hay contenedores/volúmenes los crea; si ya
# existen, recrea solo lo que cambió y reutiliza los volúmenes (la base persiste entre deploys).
# Cualquier fallo corta el script con código ≠ 0 (no se enmascara nada) y vuelca estado + logs.
# ============================================================
# -E: el trap ERR también se dispara dentro de funciones (p. ej. un `check` del smoke test que falla).
set -Eeuo pipefail

env_file="${1:?Uso: deploy.sh <env-file> [build]}"
mode="${2:-deploy}"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="$root/.docker/compose/docker-compose.staging.yml"
project="${STAGING_PROJECT:-logistics-staging}"
export STAGING_IMAGE_TAG="${STAGING_IMAGE_TAG:-staging}"

# Docker en Windows (Git Bash) necesita rutas C:/...; en Linux esto no hace nada.
to_native() {
    local path="$1"
    if command -v cygpath >/dev/null 2>&1; then cygpath -m "$path"; else printf '%s' "$path"; fi
}
[[ -f "$env_file" ]] || { echo "::error::No existe el env-file '$env_file' (correr render-env.sh antes)."; exit 1; }
env_file="$(to_native "$(cd "$(dirname "$env_file")" && pwd)/$(basename "$env_file")")"
compose_file="$(to_native "$compose_file")"
# El compose interpola ${STAGING_ENV_FILE} para el env_file de app/worker.
export STAGING_ENV_FILE="$env_file"

dc() { docker compose -p "$project" -f "$compose_file" --env-file "$env_file" "$@"; }

step() { echo; echo "::group::$*"; echo "==> $*"; }
endstep() { echo "::endgroup::"; }

# Ante cualquier fallo: estado de los contenedores y últimos logs, para diagnosticar desde el job.
on_error() {
    local code=$?
    echo "::endgroup::"
    echo "::error::Deploy de staging FALLÓ (código $code). Estado y logs:"
    dc ps -a || true
    for svc in app worker web postgres pgbouncer valkey; do
        echo "----- logs: $svc (últimas 80 líneas) -----"
        dc logs --no-color --tail=80 "$svc" 2>&1 || true
    done
    exit "$code"
}
trap on_error ERR

step "Preflight"
docker version --format 'Docker Engine {{.Server.Version}}'
docker compose version
df -h / | tail -1 | awk '{print "Disco: " $4 " libres de " $2 " (" $5 " usado)"}'
echo "Proyecto: $project · tag de imágenes: $STAGING_IMAGE_TAG · modo: $mode"
dc config --quiet
echo "Compose de staging válido."
endstep

step "Build de imágenes (app + web, toolchain dentro de Docker)"
# BuildKit con cache mounts (composer/npm) definidos en el Dockerfile: builds incrementales rápidos.
DOCKER_BUILDKIT=1 dc build --progress=plain app web
endstep

if [[ "$mode" == "build" ]]; then
    echo "Modo build: imágenes construidas OK, no se despliega."
    exit 0
fi

step "Infra: postgres + pgbouncer + valkey (espera healthy)"
dc up -d --wait --wait-timeout 180 postgres pgbouncer valkey
endstep

step "Migraciones (una sola vez, contenedor efímero con la imagen nueva)"
# Antes de recrear el web: migraciones aditivas (expand/contract, docs/deploy.md §6). Sin caches de
# arranque en este contenedor descartable (OPTIMIZE_ON_BOOT=false) para que sea rápido.
dc run --rm --no-deps -e OPTIMIZE_ON_BOOT=false app php artisan migrate --force --no-interaction
endstep

step "Deploy: up -d --build --remove-orphans (espera healthy de todos los servicios)"
dc up -d --build --remove-orphans --wait --wait-timeout 420
dc ps
endstep

step "Smoke test HTTP"
port="$(grep -E '^STAGING_HTTP_PORT=' "$env_file" | tail -1 | cut -d= -f2)"
port="${port:-8110}"
base_url="http://127.0.0.1:${port}"

# Reintenta un endpoint hasta 15 veces (2 s); falla el job si nunca da el código esperado.
check() {
    local path="$1" expect="$2" code=""
    for _ in $(seq 1 15); do
        code="$(curl -s -o /tmp/staging-smoke.out -w '%{http_code}' --max-time 10 "${base_url}${path}" || true)"
        if [[ "$code" == "$expect" ]]; then
            echo "OK   ${path} → ${code}"
            return 0
        fi
        sleep 2
    done
    echo "FAIL ${path} → ${code:-sin respuesta} (esperado ${expect})"
    head -c 600 /tmp/staging-smoke.out 2>/dev/null || true
    echo
    return 1
}

check /up 200                 # Laravel vivo (nginx → FPM → framework)
check /api/v1/health 200      # PostgreSQL+PostGIS y Valkey OK (503 si alguno falla)
grep -q '"status":"ok"' /tmp/staging-smoke.out || { echo "health no reporta status ok"; cat /tmp/staging-smoke.out; false; }
check /admin/login 200        # Panel Filament renderiza (detecta assets/manifest de Vite faltantes)

# Teselas del mapa: la URL la pide el NAVEGADOR del usuario, así que nunca puede ser localhost en un
# servidor (pasó: el mapa de flota quedaba sin fondo en staging). Falla el deploy en vez de dejarlo roto.
tiles="$(dc exec -T app php artisan config:show maps.tile_url 2>&1 | tr -s ' ' | tail -n 2)"
echo "Teselas: ${tiles}"
if grep -Eq 'localhost|127\.0\.0\.1' <<< "$tiles"; then
    echo "::error::MAP_TILE_URL apunta a localhost: el mapa quedaría sin fondo para cualquier usuario remoto. Quitá MAP_TILE_URL de STAGING_ENV_EXTRA (usa el default OSM) o poné la URL pública del tileserver."
    false
fi
endstep

trap - ERR
echo
echo "✓ Staging desplegado y sano en ${base_url} (proyecto ${project})."
