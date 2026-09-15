# 파일 위치: docker/relay/Dockerfile
# 역할: 중계 서버(uvncrepeater + websockify + nginx) 개발용 컨테이너 이미지
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        build-essential \
        ca-certificates \
        curl \
        nginx \
        libnginx-mod-stream \
        websockify \
        openssl \
        gettext-base \
        supervisor \
        tcpdump \
        netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# 리피터 빌드
RUN mkdir -p /etc/remotehelp/templates
COPY server/repeater /usr/src/uvncrepeater
RUN cd /usr/src/uvncrepeater && make clean && make release \
    && cp repeater /usr/local/bin/uvncrepeater-ac \
    && chmod 755 /usr/local/bin/uvncrepeater-ac \
    && make clean

# 리피터 전용 비권한 계정
RUN useradd -r -s /usr/sbin/nologin uvncrep || true

COPY server/repeater/uvncrepeater.remotehelp.ini /etc/uvncrepeater-ac.ini
COPY server/nginx/stream.conf /etc/remotehelp/templates/stream.conf
COPY server/nginx/remotehelp.conf /etc/remotehelp/templates/remotehelp.conf
COPY docker/relay/nginx.conf /etc/nginx/nginx.conf
COPY docker/relay/supervisord.conf /etc/supervisor/conf.d/relay.conf
COPY docker/relay/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /var/log/remotehelp /etc/remotehelp/certs /etc/nginx/conf.d-stream

EXPOSE 5501 8443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
