#
#   FederationLib FederationServer Docker Image
#
#   This image provides a PHP-FPM environment with FederationLib FederationServer
#   pre-installed, along with Nginx and Supervisor for web serving.
#

ARG PHP_VERSION=8.5

FROM ghcr.io/nosial/ncc:latest AS builder
WORKDIR /app

COPY . /app
RUN ncc project install -y && ncc build --configuration web_release

FROM ghcr.io/nosial/ncc:fpm AS production

LABEL org.opencontainers.image.title="FederationServer" \
      org.opencontainers.image.description="FederationServer Docker image" \
      org.opencontainers.image.vendor="Nosial"

ENV LOGLIB_CONSOLE_ENABLED=false
ENV LOGLIB_UDP_ENABLED=true
ENV LOGLIB_UDP_HOST=127.0.0.1
ENV LOGLIB_UDP_PORT=9003
ENV LOGLIB_UDP_TRACE_FORMAT=full

RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor ca-certificates curl libpq5 && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions sockets
RUN docker-php-ext-install -j$(nproc) pdo_mysql
RUN pecl install redis && docker-php-ext-enable redis

RUN curl -sL "https://github.com/nosial/LogLib2Server/releases/latest/download/LogLib2Server-linux-x86_64" -o /usr/bin/ll2s && \
    chmod +x /usr/bin/ll2s && apt purge -y --auto-remove curl

RUN echo "upload_max_filesize = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=builder /app/target/web/net.nosial.federation.ncc /tmp/package.ncc
RUN ncc package install --package=/tmp/package.ncc -y && rm /tmp/package.ncc

RUN mkdir -p /var/www/html /var/www/uploads /etc/configlib
COPY --from=builder /app/web_entry /var/www/html/index.php

WORKDIR /var/www/html

RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
RUN chmod 0777 /var/www/uploads && chmod 0777 /etc/configlib

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
