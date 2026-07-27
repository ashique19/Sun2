# Sun2 Cloud Agent base image: Laravel 13 on PHP 8.4 + Node for Vite.
# Do not COPY the application; Cursor checks out the repo into the workspace.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    LANG=C.UTF-8 \
    LC_ALL=C.UTF-8 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/usr/local/bin:${PATH}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        software-properties-common \
        unzip \
        zip \
    && add-apt-repository -y ppa:ondrej/php \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        nodejs \
        php8.4-cli \
        php8.4-bcmath \
        php8.4-curl \
        php8.4-gd \
        php8.4-mbstring \
        php8.4-sqlite3 \
        php8.4-xml \
        php8.4-zip \
        php8.4-intl \
        php8.4-opcache \
        php8.4-readline \
    && update-alternatives --set php /usr/bin/php8.4 \
    && curl -fsSL https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Smoke-check toolchain versions during image build.
RUN php -v \
    && composer -V \
    && node -v \
    && npm -v \
    && php -m | grep -E '^(sqlite3|mbstring|xml|curl|gd|zip|bcmath|pdo_sqlite)$'

WORKDIR /workspace
