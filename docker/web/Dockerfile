# 파일 위치: docker/web/Dockerfile
# 역할: CodeIgniter 3 포털 실행용 PHP-Apache 컨테이너
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
        libzip-dev \
        unzip \
    && docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/web/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/web/php.ini /usr/local/etc/php/conf.d/remotehelp.ini

WORKDIR /var/www/html
