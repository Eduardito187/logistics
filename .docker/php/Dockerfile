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
        shadow \
        tzdata \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/* /tmp/*

# Timezone Bolivia
ENV TZ=America/La_Paz
RUN cp /usr/share/zoneinfo/${TZ} /etc/localtime && echo "${TZ}" > /etc/timezone

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
COPY .docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY --chmod=0755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

USER logistics

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]

# ============================================================
# Prod stage — código copiado, optimizado
# ============================================================
FROM base AS prod

COPY .docker/php/php.prod.ini /usr/local/etc/php/conf.d/zzz-logistics.ini
COPY .docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY --chmod=0755 .docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

COPY --chown=${UID}:${GID} . /var/www/html

USER logistics

RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts \
    && composer clear-cache

RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
