# Imagen de producción para Render: PHP 8.3 con Apache. Solo public/ es accesible desde la web.
# Sin dependencias de desarrollo (ni PHPUnit ni pcov). Los secretos llegan por variables de entorno.

FROM php:8.3-apache

# Extensión de PostgreSQL. libpq se queda instalada: pdo_pgsql la necesita en tiempo de ejecución.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/planificador

# Primero solo composer.json/lock: si no cambian, Docker reutiliza esta capa.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-autoloader --no-scripts

COPY src ./src
COPY public ./public
COPY bin ./bin
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && rm /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-planificador.ini
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/arrancar.sh /usr/local/bin/arrancar

# El código pertenece a root y Apache (www-data) solo puede leerlo.
RUN chmod 755 /usr/local/bin/arrancar \
    && chown -R root:root /var/www/planificador \
    && chmod -R u=rwX,go=rX /var/www/planificador

# Render indica el puerto en la variable PORT (por defecto 10000).
EXPOSE 10000
CMD ["arrancar"]
