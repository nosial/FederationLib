# --- STAGE 1: BUILDER (Compiles the NCC Package) ---
FROM php:8.3-fpm AS builder

# Set the working directory for the application source code
WORKDIR /app

# 1. Install necessary OS Dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    zip \
    make \
    wget \
    gnupg \
    libc-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# 2. Install Required PHP Extensions
RUN docker-php-ext-install -j$(nproc) zip pdo_mysql

# 3. Install PECL extensions
RUN pecl install msgpack \
    && docker-php-ext-enable msgpack

# 4. Download and Install ncc (PHAR package manager)
RUN echo "Installing ncc package manager..." \
    && git clone --recurse-submodules https://git.n64.cc/nosial/ncc /tmp/ncc \
    && cd /tmp/ncc \
    && git checkout dev \
    && make target/ncc.phar \
    && target/install.sh \
    && mv /tmp/ncc /tmp/ncc-install \
    && cd /

# 4. Copy the Application Source Code
COPY . /app

# 5. Install Project Dependencies and Build the NCC Package
RUN ncc project install -y && ncc build --configuration=web_release


# --- STAGE 2: PRODUCTION (Final Runtime Image) ---
FROM php:8.3-fpm AS production

# Metadata labels
LABEL org.opencontainers.image.title="FederationLib" \
      org.opencontainers.image.version="1.0.0" \
      org.opencontainers.image.vendor="" \
      org.opencontainers.image.authors="" \
      org.opencontainers.image.description="" \
      org.opencontainers.image.url="" \
      org.opencontainers.image.licenses="" \
      ncc.package="net.nosial.federation" \
      ncc.version="1.0.0" \
      ncc.entry_point="web_entry"

# Install Nginx, Supervisor and other minimal runtime dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    supervisor \
    libpq5 \
    libzip-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# 1. Install Required PHP Extensions (runtime only)
RUN docker-php-ext-install -j$(nproc) zip sockets pdo_mysql

# 1.1 Install PECL extensions
RUN pecl install redis msgpack && docker-php-ext-enable redis msgpack

# 1.2 Configure PHP for file uploads (match nginx client_max_body_size)
RUN echo "upload_max_filesize = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 1G" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/uploads.ini

# 2. Install ncc by running the installation script (sets up PHP environment properly)
COPY --from=builder /tmp/ncc-install /tmp/ncc-install
RUN cd /tmp/ncc-install && ./target/install.sh && cd / && rm -rf /tmp/ncc-install

# 3. Install the compiled package and its dependencies
COPY --from=builder /app/target/web/net.nosial.federation.ncc /tmp/package.ncc
RUN ncc package install --package=/tmp/package.ncc -y && rm /tmp/package.ncc

# 4. Copy the web entry point file
RUN mkdir -p /var/www/html /var/www/uploads /etc/configlib
COPY --from=builder /app/web_entry /var/www/html/index.php

# Set working directory
WORKDIR /var/www/html

# 5. Configure Files
RUN rm -f /etc/nginx/sites-enabled/default
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod 0777 /var/www/uploads && chmod 0777 /etc/configlib

# 6. Expose port 8080
EXPOSE 8080

# 7. Define the startup command
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
RUN chmod 0777 /etc/configlib
ENTRYPOINT ["docker-entrypoint.sh"]