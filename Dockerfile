FROM git.n64.cc/nosial/ncc:latest-fpm AS base

# ----------------------------- Metadata labels ------------------------------
LABEL maintainer="Netkas <netkas@n64.cc>" \
      version="1.0" \
      description="FederationServer Docker image based off ncc-fpm" \
      application="FederationServer" \
      base_image="ncc:latest-fpm"

# Environment variable for non-interactive installations
ENV DEBIAN_FRONTEND=noninteractive

# ----------------------------- System Dependencies --------------------------
# Update system packages and install required dependencies in one step
RUN apt-get update -yqq && apt-get install -yqq --no-install-recommends \
    libpq-dev \
    libzip-dev \
    cron \
    supervisor \
    mariadb-client \
    libcurl4-openssl-dev \
    libmemcached-dev \
    redis \
    libgd-dev \
    nginx \
    python3-colorama \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ----------------------------- PHP Extensions -------------------------------
# Install PHP extensions and enable additional ones
RUN docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    mysqli \
    opcache \
    sockets \
    pcntl && \
    pecl install redis memcached && \
    docker-php-ext-enable redis memcached


# ----------------------------- Project Build ---------------------------------
# Set build directory and copy pre-needed project files
WORKDIR /tmp/build
COPY . .

RUN ncc build --config release --build-source --log-level debug && \
    ncc package install --package=build/release/net.nosial.federation.ncc --build-source -y --log-level=debug

# Clean up
RUN rm -rf /tmp/build && rm -rf /var/www/html/*

# Copy over the required files and set the correct permissions
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY public/index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html
RUN mkdir -p /etc/config && chown -R www-data:www-data /etc/config && chmod -R 755 /etc/config

# ----------------------------- Cleanup ---------------------
WORKDIR /

# ----------------------------- Port Exposing ---------------------------------
EXPOSE 8500

# ----------------------------- Container Startup ----------------------------
# Copy over entrypoint script and set it as executable
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Environment
ENV CONFIGLIB_PATH="/etc/config"
ENV LOGLIB_UDP_ENABLED="true"
ENV LOGLIB_UDP_HOST="127.0.0.1"
ENV LOGLIB_UDP_PORT="5131"
ENV LOGLIB_UDP_TRACE_FORMAT="full"
ENV LOGLIB_CONSOLE_ENABLED="true"
ENV LOGLIB_CONSOLE_TRACE_FORMAT="full"

# Set the entrypoint
ENTRYPOINT ["/usr/bin/bash", "/usr/local/bin/entrypoint.sh"]
