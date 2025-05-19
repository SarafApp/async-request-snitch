FROM sarafapp/php:8.3-cli

WORKDIR /app/
ADD . /app

RUN composer install

EXPOSE 9898

CMD ["php", "/app/server.php"]