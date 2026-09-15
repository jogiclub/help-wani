# 파일 위치: docker/relay/Dockerfile
# 역할: 중계 서버(Node.js) + TLS 종료용 nginx 컨테이너
FROM node:20-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
        openssl \
        supervisor \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 의존성 먼저 설치해 레이어 캐시를 활용한다.
COPY relay/package.json relay/package-lock.json* ./
RUN npm install --omit=dev --no-audit --no-fund

COPY relay/src ./src

RUN mkdir -p /etc/remotehelp/templates /etc/remotehelp/certs /var/log/remotehelp
COPY server/nginx/remotehelp.conf /etc/remotehelp/templates/remotehelp.conf
COPY server/nginx/remotehelp-ws.inc /etc/nginx/remotehelp-ws.inc
COPY docker/relay/nginx.conf /etc/nginx/nginx.conf
COPY docker/relay/supervisord.conf /etc/supervisor/conf.d/relay.conf
COPY docker/relay/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default

EXPOSE 8443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf", "-n"]
