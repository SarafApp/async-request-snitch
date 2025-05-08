FROM php:8.1-cli-alpine

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions
RUN install-php-extensions @composer gd intl

WORKDIR /app/
ADD . /app

RUN composer install
RUN mv .env.server .env

EXPOSE 9898

CMD ["php", "/app/server.php"]