# 파일 위치: docker/web/Dockerfile
# 역할: 포털 앞단 Apache (PHP 없음). TLS 없는 http 를 받아 정적 파일(/assets)은 직접 내주고, 나머지는 FastAPI(api)로 넘긴다.
#       고객 IP 복원(mod_remoteip), http->https 전환, 상담 실시간·원격 뷰어 웹소켓 경유도 맡는다(apache-vhost.conf).
FROM debian:trixie-20261005-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        apache2 \
        ca-certificates \
    && a2enmod rewrite headers proxy proxy_http proxy_wstunnel ssl remoteip \
    && rm -rf /var/lib/apt/lists/* \
    # Debian 기본 로그 파일 대신 컨테이너 로그로 보낸다.
    && ln -sf /dev/stderr /var/log/apache2/error.log \
    && ln -sf /dev/stdout /var/log/apache2/access.log \
    && ln -sf /dev/stdout /var/log/apache2/other_vhosts_access.log

COPY docker/web/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
EXPOSE 80
# apache2ctl 이 /etc/apache2/envvars 를 읽은 뒤 앞에서 실행한다. SIGWINCH 는 Apache 의 정상 종료 신호다.
STOPSIGNAL SIGWINCH
CMD ["apache2ctl", "-D", "FOREGROUND"]
