#
#   FederationLib FederationServer Docker Image
#
#   This image provides a PHP-FPM environment with FederationLib FederationServer
#   pre-installed, along with Nginx and Supervisor for web serving.
#

ARG NCC_BUILDER_IMAGE=ghcr.io/nosial/ncc:dev
ARG NCC_FPM_IMAGE=ghcr.io/nosial/ncc:dev-fpm

FROM ${NCC_BUILDER_IMAGE} AS builder
WORKDIR /app
COPY . /app
RUN ncc build --configuration web_release

FROM ${NCC_FPM_IMAGE} AS production

LABEL org.opencontainers.image.title="FederationServer" \
      org.opencontainers.image.description="FederationServer Docker image" \
      org.opencontainers.image.vendor="Nosial"

ENV LOGLIB_CONSOLE_ENABLED=false \
    LOGLIB_UDP_ENABLED=true \
    LOGLIB_UDP_HOST=127.0.0.1 \
    LOGLIB_UDP_PORT=9003 \
    LOGLIB_UDP_TRACE_FORMAT=full

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY crontab /etc/federation/crontab
COPY docker-functions.sh /usr/local/lib/federationlib/docker-functions.sh
COPY bayesian_recovery/placeholder_rules.json /etc/federation/bayesian_placeholder_rules.json
COPY bayesian_recovery/supervisord.bayesian.conf /etc/supervisor/bayesian.conf
COPY bayesian_recovery/supervisord.temporary.conf /etc/supervisor/temporary_bayesian.conf
COPY docker-entrypoint.sh docker-healthcheck.sh bayesian_recovery/start_bayesian.sh bayesian_recovery/temporary_start_bayesian.sh bayesian_recovery/stop_temporary_bayesian.sh /usr/local/bin/

RUN apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends nginx supervisor ca-certificates curl libpq5 openjdk-21-jdk-headless \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions sockets apcu gd exif \
    && echo "apc.enable_cli=1" >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini \
    && docker-php-ext-install -j$(nproc) pdo_mysql \
    && pecl install redis && docker-php-ext-enable redis \
    && curl -sL "https://github.com/nosial/LogLib2Server/releases/latest/download/LogLib2Server-linux-x86_64" -o /usr/bin/ll2s \
    && curl -sL "https://github.com/nosial/BayesianServer/releases/latest/download/bayesian-server.jar" -o /usr/bin/bayesian.jar \
    && curl -sL "https://github.com/aptible/supercronic/releases/latest/download/supercronic-linux-amd64" -o /usr/bin/supercronic \
    && chmod +x /usr/bin/ll2s /usr/bin/bayesian.jar /usr/bin/supercronic /usr/local/bin/docker-entrypoint.sh /usr/local/bin/docker-healthcheck.sh /usr/local/bin/start_bayesian.sh /usr/local/bin/temporary_start_bayesian.sh /usr/local/bin/stop_temporary_bayesian.sh \
    && echo "upload_max_filesize = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && rm -f /etc/nginx/sites-enabled/default

COPY --from=builder /app/target/web/net.nosial.federation.ncc /tmp/package.ncc
RUN ncc package install --package=/tmp/package.ncc -y && rm /tmp/package.ncc
RUN ncc install --package="nosial/BayesianPlugin@github" --yes
RUN printf '%s\n' 'display_errors = Off' 'display_startup_errors = Off' > /usr/local/etc/php/conf.d/errors.ini

COPY --from=builder /app/web_entry /var/www/html/index.php
RUN mkdir -p /var/www/uploads /etc/configlib \
    && chmod 0777 /var/www/uploads /etc/configlib

WORKDIR /var/www/html
EXPOSE 7000
ENTRYPOINT ["docker-entrypoint.sh"]

HEALTHCHECK --interval=15s --timeout=10s --start-period=5s --retries=2 \
    CMD ["docker-healthcheck.sh"]