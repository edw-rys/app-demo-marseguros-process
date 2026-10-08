FROM php:8.3-fpm-alpine

LABEL maintainer="marseguros" \
      org.opencontainers.image.title="Gmail Docs Validator · admin"

# Rutas que el `docker-compose` monta como volúmenes. Se crean en la imagen
# para que existan con el owner bien: un volumen nombrado hereda los permisos
# del punto de montaje de la imagen, y si no existe, Docker lo crea como root
# y el worker de cola no puede escribir dentro.
ENV APP_ROOT=/var/www/html \
    DATA_ROOT=/var/www/gmail-docs

ENV PHP_MEMORY_LIMIT=512M \
    PHP_UPLOAD_MAX_FILESIZE=64M \
    PHP_POST_MAX_SIZE=64M \
    PHP_MAX_EXECUTION_TIME=300

# nginx + supervisor (web y scheduler) + cliente de healthcheck.
# `tini` como PID 1: sin él, php-fpm no recibe las señales de `docker stop`.
RUN apk add --no-cache \
        nginx \
        supervisor \
        tini \
        curl \
        bash \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        icu-dev \
        sqlite-dev \
        $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_sqlite \
        mbstring \
        zip \
        bcmath \
        pcntl \
        opcache \
        dom \
        xml \
        simplexml \
        fileinfo \
        exif \
        gd \
        intl

RUN rm -rf /var/cache/apk/* /tmp/pear

RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# `www-data` es uid/gid 82 en Alpine y los workers Python corren como 1000. El
# volumen `gd-attachments` es compartido: si los dos lados no pueden leerlo, el
# worker abre un archivo que Laravel acaba de escribir y falla con Permission
# denied. Alinearlos evita tener que jugar con `chgrp` en cada arranque.
#
# El orden importa: primero se renombra el grupo 82 a 1000 y se mueve el home de
# www-data, y recién después se cambia el uid. Al revés, `usermod` deja el
# usuario apuntando a un grupo que ya no existe.
RUN apk add --no-cache shadow \
    && groupmod -g 1000 www-data \
    && usermod -u 1000 -g 1000 www-data

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# El bucle de polling va en un script aparte, NO en el `command=` del .conf:
# supervisord no es un shell y manglea las variables de shell que ahí se
# escriban (ver el comentario en `docker/gmail-poll.sh`).
COPY docker/gmail-poll.sh /usr/local/bin/gmail-poll.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
# El pool va en `conf.d` con prefijo `zz-` para que se cargue DESPUÉS del
# `www.conf` de la imagen oficial, que pone `pm.max_children = 5`. Sin ese
# prefijo el orden alfabético lo deja ganar a este y los workers se quedan en 5
# — insuficiente, porque cada conexión SSE retiene uno durante toda su vida.
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-zz-app-pool.conf
COPY docker/init.sh /init.sh

RUN chmod +x /init.sh /usr/local/bin/gmail-poll.sh \
    && mkdir -p "$APP_ROOT" "$DATA_ROOT/db" "$DATA_ROOT/attachments" \
        /var/log/supervisor /var/run \
    && chown -R www-data:www-data "$APP_ROOT" "$DATA_ROOT" /var/log/supervisor /var/run

# El código va primero y composer después: mientras no cambien los fuentes, la
# capa de dependencias no se invalida y un rebuild esseconds.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-progress

COPY --chown=www-data:www-data . .

# Los scripts de composer (`package:discover`, `filament:upgrade`) corren aquí
# y no en el `install` de arriba: necesitan el código completo para que
# `artisan` exista.
RUN composer run-script post-autoload-cmd \
    || composer dump-autoload --no-dev --optimize

WORKDIR $APP_ROOT

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --retries=3 --start-period=40s \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/init.sh"]