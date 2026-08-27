FROM wordpress:7.1-php8.3-apache

# Every layer down to `chown` is byte-identical to the Pro add-on repo's
# (paycrypto-me-pro) Dockerfile on purpose: Docker's build cache is content-addressed per
# layer on the local daemon, shared across any Dockerfile/build context — not scoped to this
# repo or this compose project. Two repos can therefore reuse each other's cached layers for this
# shared "WordPress dev image" shell without depending on one another at build time (whichever
# repo builds first warms the cache for both) — decoupled dev/deploy, shared cache. That only
# holds if this prefix stays identical in both Dockerfiles; repo-specific bits (the .vscode copy
# below) come last so they can never invalidate it.
RUN apt update && apt install -y gettext curl unzip nodejs npm

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN set -ex \
    && apt-get update \
    && apt-get install -y libmagickwand-dev libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libicu-dev libsodium-dev libgmp-dev

RUN docker-php-ext-install bcmath exif gd intl mysqli opcache zip sodium gmp

RUN apt-get update && apt-get install -y imagemagick pkg-config \
    && docker-php-ext-enable imagick

# Custom PHP limits (raises upload_max_filesize/post_max_size for plugin uploads)
COPY ./docker/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

RUN groupadd -g 1000 app \
    && useradd -m -u 1000 -g app -s /bin/bash app

RUN chown -R app:app /var/www/html

# Repo-specific from here down — always last, so it never breaks the shared-cache prefix above.
COPY ./.vscode /var/www/.vscode

# Troca para o novo usuário
USER app

WORKDIR /var/www/html
