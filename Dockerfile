FROM wordpress:latest

COPY ./.vscode /var/www/.vscode

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

RUN groupadd -g 1000 app \
    && useradd -m -u 1000 -g app -s /bin/bash app

RUN chown -R app:app /var/www/html

# Troca para o novo usuário
USER app

WORKDIR /var/www/html
