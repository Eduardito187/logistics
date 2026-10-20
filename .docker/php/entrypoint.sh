#!/usr/bin/env bash
# ============================================================
# Entrypoint del contenedor app — logistics
# ============================================================
# Misma lógica que el entrypoint de dis-commerce (incluidas sus lecciones de incidentes),
# adaptada a PostgreSQL.
set -e

# ------------------------------------------------------------
# 1. Esperar a PostgreSQL
# ------------------------------------------------------------
if [ -n "$DB_HOST" ]; then
    echo "[entrypoint] Esperando PostgreSQL en ${DB_HOST}:${DB_PORT:-5432}..."
    until php -r "try { new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=${DB_DATABASE:-logistics}', '${DB_USERNAME:-logistics}', '${DB_PASSWORD:-logistics}'); exit(0); } catch (\Throwable \$e) { exit(1); }" > /dev/null 2>&1; do
        sleep 1
    done
    echo "[entrypoint] PostgreSQL listo."
fi

# ------------------------------------------------------------
# 2. Directorios de storage (bind mount fresco)
# ------------------------------------------------------------
mkdir -p /var/www/html/storage/framework/{cache,sessions,views}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# ------------------------------------------------------------
# 2b. PHP-FPM — tuning del pool parametrizable por entorno (FPM_*)
# ------------------------------------------------------------
# php-fpm NO interpola variables de entorno en directivas numéricas
# (`pm.max_children = $ENV{...}` falla), así que el tuning FPM_* se genera acá a
# partir de `www.conf.template` en un include SEPARADO y escribible
# (zzz-fpm-tuning.conf), que php-fpm FUSIONA sobre el pool [www] al cargar los
# *.conf en orden alfabético (`zzz-` se aplica al final y manda). El www.conf
# horneado es inmutable (propiedad de root) y queda como fallback: si el drop-in
# no es escribible, no se toca nada y rigen sus defaults. El arranque nunca
# depende de este paso.
FPM_POOL_TARGET="/usr/local/etc/php-fpm.d/zzz-fpm-tuning.conf"
FPM_POOL_TEMPLATE="/usr/local/etc/php-fpm.d/www.conf.template"
if [[ -f "$FPM_POOL_TEMPLATE" && -w "$FPM_POOL_TARGET" ]]; then
    echo "[entrypoint] Renderizando pool php-fpm (FPM_*)..."
    sed \
        -e "s/__FPM_PM__/${FPM_PM:-dynamic}/" \
        -e "s/__FPM_MAX_CHILDREN__/${FPM_MAX_CHILDREN:-50}/" \
        -e "s/__FPM_START_SERVERS__/${FPM_START_SERVERS:-8}/" \
        -e "s/__FPM_MIN_SPARE__/${FPM_MIN_SPARE:-8}/" \
        -e "s/__FPM_MAX_SPARE__/${FPM_MAX_SPARE:-16}/" \
        -e "s/__FPM_MAX_REQUESTS__/${FPM_MAX_REQUESTS:-1000}/" \
        "$FPM_POOL_TEMPLATE" > "$FPM_POOL_TARGET"
fi

# ------------------------------------------------------------
# 3. Caches de arranque — OPT-IN explícito (OPTIMIZE_ON_BOOT=true)
# ------------------------------------------------------------
# El `config:cache` NO se hornea en la imagen: ahí el env de producción todavía
# no está inyectado y quedaría cacheado con los defaults del build (bug clásico).
# En su lugar se cachea en el arranque del contenedor, cuando el env real ya está
# presente. Es local a cada contenedor (web y workers), así que correrlo en cada
# réplica es seguro e idempotente. Dev lo deja apagado (OPTIMIZE_ON_BOOT=false).
if [ "${OPTIMIZE_ON_BOOT:-false}" = "true" ]; then
    echo "[entrypoint] OPTIMIZE_ON_BOOT=true → cacheando config/route/view/event..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    # Filament v4: cachea el descubrimiento de recursos/páginas/componentes y los
    # iconos Blade (menos reflexión y escaneo de FS por request en el admin).
    php artisan filament:optimize
fi

# ------------------------------------------------------------
# 4. Migraciones — OPT-IN explícito (AUTO_MIGRATE=true)
# ------------------------------------------------------------
# Apagado por defecto: con varias réplicas web todas correrían `migrate` a la vez.
# En cloud la migración va en un job de deploy único.
if [ -n "$DB_HOST" ] && [ "$1" = "php-fpm" ] && [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    echo "[entrypoint] AUTO_MIGRATE=true → corriendo migraciones..."
    php artisan migrate --force
fi

# ------------------------------------------------------------
# 5. Recarga de workers — SIEMPRE
# ------------------------------------------------------------
# `queue:work` mantiene el framework en memoria: sin esta señal un worker sigue ejecutando
# el código anterior al deploy (incidente real en dis-commerce, 2026-07-19).
if [ "$1" = "php-fpm" ]; then
    echo "[entrypoint] Señalizando recarga de workers..."
    for attempt in 1 2 3; do
        if php artisan queue:restart > /dev/null 2>&1; then
            echo "[entrypoint] Workers señalizados."
            break
        fi
        if [ "$attempt" = "3" ]; then
            echo "[entrypoint] AVISO: no se pudo señalizar la recarga (¿Valkey no disponible?)."
        else
            sleep 2
        fi
    done
fi

# ------------------------------------------------------------
# 6. Comando original
# ------------------------------------------------------------
exec "$@"
