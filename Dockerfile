FROM composer:2

WORKDIR /app

COPY composer.json ./
COPY src ./src
COPY icons ./icons

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

CMD ["composer", "validate", "--strict"]
