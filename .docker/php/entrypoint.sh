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
# 3. Migraciones — OPT-IN explícito (AUTO_MIGRATE=true)
# ------------------------------------------------------------
# Apagado por defecto: con varias réplicas web todas correrían `migrate` a la vez.
# En cloud la migración va en un job de deploy único.
if [ -n "$DB_HOST" ] && [ "$1" = "php-fpm" ] && [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    echo "[entrypoint] AUTO_MIGRATE=true → corriendo migraciones..."
    php artisan migrate --force
fi

# ------------------------------------------------------------
# 4. Recarga de workers — SIEMPRE
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
# 5. Comando original
# ------------------------------------------------------------
exec "$@"
