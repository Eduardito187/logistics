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
# DB_WAIT_TIMEOUT (segundos): tope de espera. 0 = esperar indefinidamente (default, comportamiento de dev).
# En despliegues (staging) se fija un tope para que una credencial mala FALLE claro en vez de colgar el
# deploy hasta el timeout del job.
# El tope se mide en TIEMPO REAL ($SECONDS), no en intentos: con credenciales malas PgBouncer puede dejar
# un intento colgado mucho tiempo (espera su propia auth contra Postgres), y contar intentos convertía
# "180" en horas. Además cada intento se corta a 10 s con `timeout`.
if [ -n "$DB_HOST" ]; then
    echo "[entrypoint] Esperando PostgreSQL en ${DB_HOST}:${DB_PORT:-5432}..."
    wait_start=$SECONDS
    until timeout 10 php -r "try { new PDO('pgsql:host=${DB_HOST};port=${DB_PORT:-5432};dbname=${DB_DATABASE:-logistics}', '${DB_USERNAME:-logistics}', '${DB_PASSWORD:-logistics}'); exit(0); } catch (\Throwable \$e) { exit(1); }" > /dev/null 2>&1; do
        if [[ "${DB_WAIT_TIMEOUT:-0}" -gt 0 && $((SECONDS - wait_start)) -ge "${DB_WAIT_TIMEOUT}" ]]; then
            echo "[entrypoint] ERROR: PostgreSQL no aceptó la conexión en ${DB_WAIT_TIMEOUT}s (¿host caído o credenciales incorrectas?)." >&2
            exit 1
        fi
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
# Disco `public` de Laravel: carpeta destino de la media subida (fotos de POD/daños/collages, etc.).
mkdir -p /var/www/html/storage/app/public

# ------------------------------------------------------------
# 2a-bis. Symlink public/storage -> storage/app/public (equivalente a `php artisan storage:link`)
# ------------------------------------------------------------
# El symlink se hornea en la imagen (Dockerfile), PERO en DEV el bind mount del código monta el
# `public/` del host ENCIMA y TAPA el symlink del build → sin esto, la media subida al disco `public`
# no se sirve por `/storage/...` (y nginx, que resuelve /storage al symlink, devolvería 404). Se
# (re)crea en cada arranque de forma idempotente con `ln -sfn` (más robusto que `storage:link` en un
# entrypoint: no necesita bootear la app ni falla si el enlace ya existe). En PROD (sin bind mount) es
# un no-op: el enlace ya apunta bien. Si el bind mount del host no admite symlinks, solo avisa.
if ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage 2>/dev/null; then
    echo "[entrypoint] Symlink public/storage -> storage/app/public listo (media del disco public servible)."
else
    echo "[entrypoint] AVISO: no se pudo crear public/storage (¿el bind mount del host no admite symlinks?)." >&2
fi

# ------------------------------------------------------------
# 2a. Autoloader de Composer — sincronizar en DEV (COMPOSER_INSTALL_ON_BOOT=true)
# ------------------------------------------------------------
# El monolito modular suma paquetes (packages/<ctx>) con su propio prefijo PSR-4. Al
# agregar un paquete nuevo, su prefijo NO entra al autoloader del contenedor hasta volver
# a correr composer; mientras tanto, una ruta que referencia su controller tira
# "Invalid route action" (la clase no se puede autoload-ear y aborta el grupo entero).
# En DEV sincronizamos vendor con composer.lock en el arranque del contenedor WEB, para
# que los paquetes nuevos se carguen solos, sin comandos extra. Tolerante: si falla (p. ej.
# sin red para una dependencia no cacheada) solo avisa, no tumba el arranque.
# PROD NO activa esta var (su imagen hornea el autoloader autoritativo en build); queda false.
if [ "$1" = "php-fpm" ] && [ "${COMPOSER_INSTALL_ON_BOOT:-false}" = "true" ]; then
    echo "[entrypoint] COMPOSER_INSTALL_ON_BOOT=true → sincronizando dependencias/autoloader (dev)..."
    composer install --no-interaction --no-scripts --no-progress \
        || echo "[entrypoint] AVISO: no se pudo sincronizar composer (¿sin red o lock desfasado?)."
fi

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
    # Limpiar los caches LOCALES (archivos en bootstrap/cache) antes de regenerarlos: en un
    # `docker restart` el filesystem del contenedor persiste, así que `route:cache` sobre rutas
    # ya cacheadas carga una CompiledRouteCollection (no una RouteCollection fresca) y revienta
    # con TypeError en RouteCacheCommand::buildRouteCacheFile(). NO usar `optimize:clear` aquí:
    # ese también hace `cache:clear` y vaciaría el Valkey COMPARTIDO en cada arranque de cada
    # réplica. Estos `*:clear` solo tocan archivos locales y son idempotentes.
    php artisan route:clear
    php artisan config:clear
    php artisan view:clear
    php artisan event:clear
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
# 4b. Reconciliar "Pedidos" desde los envíos — OPT-IN (AUTO_BACKFILL_ORDERS=true)
# ------------------------------------------------------------
# "Pedidos" (commerce Orders) debe reflejar los envíos que entraron por ingesta/seeders sin pasar por el
# checkout. Idempotente (salta las que ya existen), tolerante: no tumba el arranque si falla. En DEV se activa
# para que `docker compose up` deje Pedidos poblado sin correr nada a mano; en cloud lo hace el job de deploy
# (scripts/staging/deploy.sh), así que ahí queda apagado para no correrlo desde cada réplica web.
if [ -n "$DB_HOST" ] && [ "$1" = "php-fpm" ] && [ "${AUTO_BACKFILL_ORDERS:-false}" = "true" ]; then
    echo "[entrypoint] AUTO_BACKFILL_ORDERS=true → reconciliando Pedidos desde envíos..."
    php artisan commerce:orders:backfill-from-shipments --force --no-interaction \
        || echo "[entrypoint] AVISO: el backfill de Pedidos falló; el arranque sigue."
    # Recupera el tracking de entregas ya hechas en execution que quedaron atascadas en el estado visible
    # (p. ej. «Cargada»/«Procesando») por un evento perdido. Idempotente. Misma puerta turnkey.
    echo "[entrypoint] → reproyectando tracking desde las entregas de execution..."
    php artisan logistics:tracking:reconcile-from-execution --force \
        || echo "[entrypoint] AVISO: el reconcile de tracking falló; el arranque sigue."
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
# 5b. Limpieza de locks colgados del scheduler — SIEMPRE
# ------------------------------------------------------------
# `withoutOverlapping()` deja un mutex en la caché (Valkey). Si una tarea se mató a mitad (deploy, OOM,
# suspensión), el mutex NO se libera y el scheduler SALTA esa tarea hasta que el lock expira → incidente real:
# el pump del outbox quedó congelado horas y los eventos no se despachaban. Un deploy NO limpia ese lock por sí
# solo (vive en Valkey, no en el proceso). Esta limpieza al arrancar lo borra, así el outbox se recupera en el
# deploy. Tolerante: Valkey puede no estar listo; no debe tumbar el arranque.
if [ "$1" = "php-fpm" ]; then
    # OJO: el comando es `schedule:clear-cache` (ScheduleClearCacheCommand), NO `schedule:clear-mutex` (ese NO
    # existe en Laravel). Borra los mutex de withoutOverlapping/onOneServer cacheados (Valkey) por el scheduler.
    echo "[entrypoint] Limpiando locks colgados del scheduler (schedule:clear-cache)..."
    php artisan schedule:clear-cache > /dev/null 2>&1 \
        || echo "[entrypoint] AVISO: no se pudo limpiar los mutex del scheduler (¿Valkey no disponible?)."
fi

# ------------------------------------------------------------
# 6. Comando original
# ------------------------------------------------------------
exec "$@"
