# syntax=docker/dockerfile:1.7

# ============================================================
# Base stage — dependencias comunes entre dev y prod
# ============================================================
# Derivado del Dockerfile de dis-commerce. Diferencia principal: PostgreSQL (pdo_pgsql)
# en vez de MariaDB, porque la plataforma logística usa PostGIS (ver ADR 003).
FROM php:8.4-fpm-alpine AS base

# Argumentos build-time para user/group (evitar problemas de permisos con bind mounts)
ARG UID=1000
ARG GID=1000

# Dependencias del sistema + extensiones PHP.
# sodium viene compilada en la imagen oficial: la usa la firma Ed25519 de etiquetas (ADR 004).
RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-dev \
        libzip-dev \
        libpq-dev \
        linux-headers \
        oniguruma-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        shadow \
        tzdata \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && for i in 1 2 3 4 5; do \
         pecl install redis && break; \
         echo "pecl install redis failed (attempt $i/5), retrying in 10s..."; \
         sleep 10; \
       done \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/* /tmp/*

# Timezone Bolivia
ENV TZ=America/La_Paz
RUN cp /usr/share/zoneinfo/${TZ} /etc/localtime && echo "${TZ}" > /etc/timezone

# Drenado graceful de PHP-FPM (M-9 auditoría). Docker manda SIGTERM al parar el
# contenedor y PHP-FPM lo interpreta como apagado INMEDIATO (corta requests en
# vuelo). SIGQUIT es el apagado GRACEFUL de FPM: deja terminar los requests activos
# antes de salir. Se define en el stage base para que lo hereden dev y prod.
STOPSIGNAL SIGQUIT

# Composer desde imagen oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuario `logistics` con UID/GID del host (Alpine trae grupos como 'ping' en GID 999)
RUN set -eux; \
    existing_group=$(getent group ${GID} | cut -d: -f1 || true); \
    if [ -n "$existing_group" ] && [ "$existing_group" != "root" ]; then \
        delgroup "$existing_group" 2>/dev/null || true; \
    fi; \
    existing_user=$(getent passwd ${UID} | cut -d: -f1 || true); \
    if [ -n "$existing_user" ] && [ "$existing_user" != "root" ]; then \
        deluser "$existing_user" 2>/dev/null || true; \
    fi; \
    addgroup -g ${GID} logistics; \
    adduser -u ${UID} -G logistics -h /home/logistics -s /bin/bash -D logistics; \
    # vendor/ y node_modules/ se crean acá con dueño logistics: los volúmenes named del compose
    # heredan el dueño del directorio de la imagen la primera vez (si no, quedan como root).
    mkdir -p /var/www/html/vendor /var/www/html/node_modules /home/logistics/.composer/cache; \
    chown -R ${UID}:${GID} /var/www/html /home/logistics

WORKDIR /var/www/html

# ============================================================
# Dev stage — Xdebug, pcov, herramientas de desarrollo
# ============================================================
FROM base AS dev

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install xdebug pcov \
    && docker-php-ext-enable xdebug pcov \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

RUN apk add --no-cache \
        make \
        vim \
        htop \
        postgresql-client \
        redis

COPY .docker/php/php.dev.ini /usr/local/etc/php/conf.d/zzz-logistics.ini
# www.conf se chownea a `logistics` para que el entrypoint (que corre como ese
# usuario) pueda re-renderizar el pool desde el template con las variables FPM_*.
COPY --chown=logistics:logistics .docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY .docker/php/www.conf.template /usr/local/etc/php-fpm.d/www.conf.template
COPY --chmod=0755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

USER logistics

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]

# ============================================================
# Vendor stage — (nota: el HEALTHCHECK va solo en el stage prod, más abajo) — instala las dependencias PHP para alimentar el build del tema
# ============================================================
# El tema Filament (resources/css/filament/admin/theme.css) hace `@import` de
# `vendor/filament/...` y app.css escanea `vendor/laravel/...` con `@source`. Como
# `vendor/` está en .dockerignore, el stage `assets` (solo Node) no lo tendría y
# `vite build` fallaría al resolver ese `@import`. Este stage produce `vendor/` y se
# copia al stage `assets`. (Reemplaza el hack de copiar vendor/filament a mano, V-19.)
FROM base AS vendor

ARG UID=1000
ARG GID=1000

USER logistics

# Se copia TODO el contexto porque composer.json usa repositorios de tipo `path`
# (packages/logistics/*): sin esas carpetas, `composer install` falla al resolverlos.
COPY --chown=${UID}:${GID} . /var/www/html

RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts \
    && composer clear-cache

# ============================================================
# Assets stage — compila el tema Filament y los assets con Vite
# ============================================================
# `public/build/` está en .gitignore, así que los assets NO vienen en el repo:
# se compilan acá y se copian a la imagen prod. Node solo vive en este stage, no
# en la imagen final. Para el tema custom de Filament ver resources/css/filament.
FROM node:22-alpine AS assets

WORKDIR /app

# Capa de dependencias cacheable: solo se reinstala si cambian los manifiestos.
COPY package.json package-lock.json ./
RUN npm ci

# Fuente necesaria para `vite build` (resources/, vite.config.js, etc.).
COPY . .

# `vendor/` (filament + laravel) debe existir para que `vite build` resuelva el `@import`
# del tema y los `@source` de Tailwind. Viene del stage `vendor` porque `.dockerignore`
# lo excluye del contexto.
COPY --from=vendor /var/www/html/vendor ./vendor

RUN npm run build

# ============================================================
# Prod stage — código copiado, optimizado
# ============================================================
# Sin Xdebug/pcov ni herramientas de dev (make/vim/htop/redis-cli): esas solo
# viven en el stage `dev`. Acá se suma supervisor para correr los workers de cola
# y el scheduler bajo un solo proceso con reinicio automático (ver docs/deploy.md).
FROM base AS prod

# Los ARG son por-stage: hay que redeclararlos para que `${UID}`/`${GID}` resuelvan
# en los COPY --chown y chown de este stage (si no, quedarían vacíos).
ARG UID=1000
ARG GID=1000

# supervisor gobierna los workers de cola y el scheduler en el contenedor de workers.
RUN apk add --no-cache supervisor \
    && mkdir -p /var/log/supervisor \
    && chown -R ${UID}:${GID} /var/log/supervisor

COPY .docker/php/php.prod.ini /usr/local/etc/php/conf.d/zzz-logistics.ini
# www.conf se chownea a `logistics` para que el entrypoint (que corre como ese
# usuario) pueda re-renderizar el pool desde el template con las variables FPM_*.
COPY --chown=logistics:logistics .docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY .docker/php/www.conf.template /usr/local/etc/php-fpm.d/www.conf.template
COPY --chmod=0755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY .docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

COPY --chown=${UID}:${GID} . /var/www/html

USER logistics

# Dependencias de producción, autoloader autoritativo (sin escaneo de FS en runtime).
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
        --optimize-autoloader --classmap-authoritative --no-scripts \
    && composer clear-cache

# Assets compilados por Vite (tema Filament + app) desde el stage `assets`, y los
# assets propios de Filament/Livewire publicados a public/ (vendor/filament, etc.).
# Ambos son derivados del código, no del env, así que es seguro hornearlos en build.
COPY --chown=${UID}:${GID} --from=assets /app/public/build /var/www/html/public/build
RUN php artisan filament:assets

# OJO: el `config:cache` NO se hace acá. En build el env de producción todavía no
# existe y quedaría cacheado con los defaults, un bug clásico de contenedores. Las
# caches de arranque las genera el entrypoint cuando OPTIMIZE_ON_BOOT=true, ya con
# el env real inyectado (ver entrypoint.sh y docs/deploy.md).

EXPOSE 9000

# HEALTHCHECK del rol WEB (M-9 auditoría): confirma que PHP-FPM está aceptando en
# :9000 (busybox `nc -z`). Es la señal de liveness del contenedor FPM.
#
# OJO: esta imagen también corre los WORKERS (supervisor, SIN FPM en :9000). Esos
# contenedores DEBEN sobreescribir este healthcheck (el docker-compose.yml ya lo
# hace con `pgrep -f queue:work`/`schedule:work`), o lo reportarán como unhealthy.
#
# READINESS a nivel de orquestador: enrutar tráfico solo cuando `/api/v1/health`
# (vía nginx → FPM) responda 200; `/up` sirve de liveness ligero. El readiness de
# aplicación (DB/Redis arriba) NO se puede medir desde dentro del contenedor FPM.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD nc -z 127.0.0.1 9000 || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
