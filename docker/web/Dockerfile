# 파일 위치: docker/web/Dockerfile
# 역할: CodeIgniter 3 포털 실행용 PHP-Apache 컨테이너 (FastAPI 전환 뒤에는 앞단 프록시)
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
        libzip-dev \
        unzip \
    && docker-php-ext-install mysqli pdo_mysql \
    && a2enmod rewrite headers proxy proxy_http proxy_wstunnel ssl remoteip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/web/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
# 포털 처리 서버 선택(PORTAL_BACKEND = php | fastapi). apache-vhost.conf 가 하나를 읽는다.
COPY docker/web/backend-php.conf docker/web/backend-fastapi.conf /etc/apache2/remotehelp/
COPY docker/web/php.ini /usr/local/etc/php/conf.d/remotehelp.ini

WORKDIR /var/www/html
