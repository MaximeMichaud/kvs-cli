FROM docker:29.8.2-cli AS docker-cli
FROM composer:2.10.3 AS composer
FROM php:8.1.34-cli-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash-completion default-mysql-client fish git unzip which zsh zstd \
        libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl mbstring mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
ARG KVS_CLI_TEST_UID=1000
ARG KVS_CLI_TEST_GID=1000
RUN if ! getent group "$KVS_CLI_TEST_GID" >/dev/null; then \
        groupadd --gid "$KVS_CLI_TEST_GID" kvs-test; \
    fi \
    && if ! getent passwd "$KVS_CLI_TEST_UID" >/dev/null; then \
        useradd --uid "$KVS_CLI_TEST_UID" --gid "$KVS_CLI_TEST_GID" --create-home kvs-test; \
    fi
USER ${KVS_CLI_TEST_UID}:${KVS_CLI_TEST_GID}
WORKDIR /work
