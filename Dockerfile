FROM sarafapp/php:8.3-cli

WORKDIR /app/
ADD . /app

RUN composer install
RUN mv .env.server .env

EXPOSE 9898

CMD ["php", "/app/server.php"]