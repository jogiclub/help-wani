#!/bin/bash
# 파일 위치: docker/relay/entrypoint.sh
# 역할: 개발용 자체 서명 인증서 생성 후 nginx 설정 템플릿을 치환하고 서비스 기동
set -e

CERT_DIR=/etc/remotehelp/certs
export RELAY_SSL_CERT="${RELAY_SSL_CERT:-$CERT_DIR/relay.crt}"
export RELAY_SSL_KEY="${RELAY_SSL_KEY:-$CERT_DIR/relay.key}"
export RELAY_SERVER_NAME="${RELAY_SERVER_NAME:-localhost}"
export RELAY_HTTPS_PORT="${RELAY_HTTPS_PORT:-8443}"
export RELAY_UPSTREAM="${RELAY_UPSTREAM:-127.0.0.1:8081}"

if [ ! -f "$RELAY_SSL_CERT" ]; then
    echo "[entrypoint] 개발용 자체 서명 인증서를 생성합니다: $RELAY_SERVER_NAME"
    mkdir -p "$CERT_DIR"
    openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
        -keyout "$RELAY_SSL_KEY" -out "$RELAY_SSL_CERT" \
        -subj "/CN=$RELAY_SERVER_NAME" \
        -addext "subjectAltName=DNS:$RELAY_SERVER_NAME,DNS:relay,DNS:localhost,IP:127.0.0.1" 2>/dev/null
fi

SUBST='${RELAY_SSL_CERT} ${RELAY_SSL_KEY} ${RELAY_SERVER_NAME} ${RELAY_HTTPS_PORT} ${RELAY_UPSTREAM}'
envsubst "$SUBST" < /etc/remotehelp/templates/remotehelp.conf > /etc/nginx/sites-enabled/remotehelp.conf

nginx -t

exec "$@"
