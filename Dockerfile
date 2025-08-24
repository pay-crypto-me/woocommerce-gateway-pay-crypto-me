FROM wordpress:latest

RUN apt update && apt install -y curl unzip

RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

RUN groupadd -g 1000 app \
    && useradd -m -u 1000 -g app -s /bin/bash app

RUN chown -R app:app /var/www/html

# Troca para o novo usuário
USER app

WORKDIR /var/www/html
