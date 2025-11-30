FROM wordpress:latest

COPY ./.vscode /var/www/.vscode

RUN apt update && apt install -y gettext curl unzip nodejs npm

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN groupadd -g 1000 app \
    && useradd -m -u 1000 -g app -s /bin/bash app

RUN chown -R app:app /var/www/html

# Troca para o novo usuário
USER app

WORKDIR /var/www/html
