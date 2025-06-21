# -----------------------------------------------------------------------------
# Dockerfile for PHP 8.3 + FPM with Cron support and Supervisor
# -----------------------------------------------------------------------------

# Base image: Official PHP 8.3 with FPM
FROM php:8.3-fpm AS base

# ----------------------------- Metadata labels ------------------------------
LABEL maintainer="Netkas <netkas@n64.cc>" \
      version="1.0" \
      description="FederationServer Docker image based off PHP 8.3 FPM and NCC" \
      application="FederationServer" \
      base_image="php:8.3-fpm"

# Environment variable for non-interactive installations
ENV DEBIAN_FRONTEND=noninteractive

# ----------------------------- System Dependencies --------------------------
# Update system packages and install required dependencies in one step
RUN apt-get update -yqq && apt-get install -yqq --no-install-recommends \
    git \
    libpq-dev \
    libzip-dev \
    zip \
    make \
    wget \
    gnupg \
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
    curl \
    opcache \
    zip \
    sockets \
    pcntl && \
    pecl install redis memcached && \
    docker-php-ext-enable redis memcached

# ----------------------------- Additional Tools -----------------------------
# Install Phive (Package Manager for PHAR libraries) and global tools in one step
RUN wget -O /usr/local/bin/phive https://phar.io/releases/phive.phar && \
    wget -O /usr/local/bin/phive.asc https://phar.io/releases/phive.phar.asc && \
    gpg --keyserver hkps://keys.openpgp.org --recv-keys 0x9D8A98B29B2D5D79 && \
    gpg --verify /usr/local/bin/phive.asc /usr/local/bin/phive && \
    chmod +x /usr/local/bin/phive && \
    phive install phpab --global --trust-gpg-keys 0x2A8299CE842DD38C

# ----------------------------- Clone and Build NCC --------------------------
# Clone the NCC repository, build the project, and install it
RUN git clone https://git.n64.cc/nosial/ncc.git && \
    cd ncc && \
    make redist && \
    NCC_DIR=$(find build/ -type d -name "ncc_*" | head -n 1) && \
    if [ -z "$NCC_DIR" ]; then \
      echo "NCC build directory not found"; \
      exit 1; \
    fi && \
    php "$NCC_DIR/INSTALL" --auto && \
    cd .. && rm -rf ncc

# ----------------------------- Project Build ---------------------------------
# Set build directory and copy pre-needed project files
WORKDIR /tmp/build
COPY . .

RUN ncc build --config release --build-source --log-level debug && \
    ncc package install --package=build/release/net.nosial.federation.ncc --build-source -y --log-level=debug

# Clean up
RUN rm -rf /tmp/build && rm -rf /var/www/html/*

# Copy over the required files
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY public/index.php /var/www/html/index.php
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# ----------------------------- Environment Configuration ---------------------------
# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
# Copy docker.conf & zz-docker.conf for PHP-FPM
COPY docker/docker.conf /usr/local/etc/php-fpm.d/docker.conf
COPY docker/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
# Copy the logging server script over
COPY docker/logger.py /logger.py

# Configure php.ini and enable error and log it to /var/log rather than stdout
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && \
    sed -i 's/^;error_log = php_errors.log/error_log = \/var\/log\/php_errors.log/' /usr/local/etc/php/php.ini && \
    sed -i 's/^;log_errors = On/log_errors = On/' /usr/local/etc/php/php.ini && \
    sed -i 's/^;error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT/error_reporting = E_ALL/' /usr/local/etc/php/php.ini && \
    sed -i 's/^;display_errors = Off/display_errors = On/' /usr/local/etc/php/php.ini && \
    sed -i 's/^;date.timezone =/date.timezone = UTC/' /usr/local/etc/php/php.ini


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
