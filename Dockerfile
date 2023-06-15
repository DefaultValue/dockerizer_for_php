FROM php:8.0.2-cli

RUN apt update

RUN apt install -y libzip-dev zip unzip --no-install-recommends

RUN docker-php-ext-install pcntl pdo_mysql zip

RUN curl -k -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN groupadd -g 1000 docker ; useradd -u 1000 -g docker -m docker