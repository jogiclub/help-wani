#!/bin/bash
# 파일 위치: server/install.sh
# 역할: Ubuntu 24.04 중계 서버에 uvncrepeater, websockify, nginx(stream/http) 를 설치하고 서비스로 등록
#
# 사용법:
#   sudo RELAY_SERVER_NAME=relay.example.com \
#        PORTAL_UPSTREAM=portal.example.com:80 \
#        PORTAL_HOST=portal.example.com \
#        RELAY_AUTH_SHARED_SECRET=... \
#        ./install.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

RELAY_SERVER_NAME="${RELAY_SERVER_NAME:-}"
PORTAL_UPSTREAM="${PORTAL_UPSTREAM:-}"
PORTAL_HOST="${PORTAL_HOST:-$PORTAL_UPSTREAM}"
RELAY_AUTH_SHARED_SECRET="${RELAY_AUTH_SHARED_SECRET:-}"
RELAY_HTTPS_PORT="${RELAY_HTTPS_PORT:-443}"
LETSENCRYPT="${LETSENCRYPT:-1}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"

if [ "$(id -u)" -ne 0 ]; then
    echo "root 권한으로 실행해 주세요." >&2
    exit 1
fi

for v in RELAY_SERVER_NAME PORTAL_UPSTREAM RELAY_AUTH_SHARED_SECRET; do
    if [ -z "${!v}" ]; then
        echo "환경변수 $v 가 필요합니다." >&2
        exit 1
    fi
done

echo "== 1. 패키지 설치"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y build-essential nginx libnginx-mod-stream websockify \
    gettext-base openssl ufw logrotate ca-certificates

echo "== 2. 리피터 빌드 및 설치"
cd "$SCRIPT_DIR/repeater"
make clean >/dev/null 2>&1 || true
make release
install -m 0755 repeater /usr/local/bin/uvncrepeater-ac
make clean >/dev/null 2>&1 || true

id -u uvncrep >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin uvncrep
install -m 0644 "$SCRIPT_DIR/repeater/uvncrepeater.remotehelp.ini" /etc/uvncrepeater-ac.ini

mkdir -p /var/log/remotehelp
chown uvncrep:adm /var/log/remotehelp
chmod 0750 /var/log/remotehelp

echo "== 3. 인증서 준비"
CERT_DIR=/etc/remotehelp/certs
mkdir -p "$CERT_DIR"

if [ "$LETSENCRYPT" = "1" ]; then
    apt-get install -y certbot
    if [ ! -d "/etc/letsencrypt/live/$RELAY_SERVER_NAME" ]; then
        systemctl stop nginx || true
        certbot certonly --standalone -d "$RELAY_SERVER_NAME" \
            --agree-tos --non-interactive \
            ${CERTBOT_EMAIL:+--email "$CERTBOT_EMAIL"} ${CERTBOT_EMAIL:---register-unsafely-without-email}
    fi
    export RELAY_SSL_CERT="/etc/letsencrypt/live/$RELAY_SERVER_NAME/fullchain.pem"
    export RELAY_SSL_KEY="/etc/letsencrypt/live/$RELAY_SERVER_NAME/privkey.pem"

    # 갱신 시 nginx 를 다시 읽도록 훅 등록 (stream 리스너도 함께 갱신된다)
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/remotehelp-nginx.sh <<'HOOK'
#!/bin/bash
systemctl reload nginx
HOOK
    chmod +x /etc/letsencrypt/renewal-hooks/deploy/remotehelp-nginx.sh
else
    export RELAY_SSL_CERT="$CERT_DIR/relay.crt"
    export RELAY_SSL_KEY="$CERT_DIR/relay.key"
    if [ ! -f "$RELAY_SSL_CERT" ]; then
        openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
            -keyout "$RELAY_SSL_KEY" -out "$RELAY_SSL_CERT" \
            -subj "/CN=$RELAY_SERVER_NAME" \
            -addext "subjectAltName=DNS:$RELAY_SERVER_NAME"
    fi
fi

echo "== 4. nginx 설정"
export RELAY_SERVER_NAME PORTAL_UPSTREAM PORTAL_HOST RELAY_AUTH_SHARED_SECRET RELAY_HTTPS_PORT
SUBST='${RELAY_SSL_CERT} ${RELAY_SSL_KEY} ${RELAY_SERVER_NAME} ${RELAY_HTTPS_PORT} ${PORTAL_UPSTREAM} ${PORTAL_HOST} ${RELAY_AUTH_SHARED_SECRET}'

mkdir -p /etc/nginx/conf.d-stream
envsubst "$SUBST" < "$SCRIPT_DIR/nginx/stream.conf"     > /etc/nginx/conf.d-stream/stream.conf
envsubst "$SUBST" < "$SCRIPT_DIR/nginx/remotehelp.conf" > /etc/nginx/sites-available/remotehelp.conf
ln -sf /etc/nginx/sites-available/remotehelp.conf /etc/nginx/sites-enabled/remotehelp.conf
rm -f /etc/nginx/sites-enabled/default

# stream 블록은 http 블록 밖에서 include 되어야 한다.
if ! grep -q "conf.d-stream" /etc/nginx/nginx.conf; then
    printf '\n# RemoteHelp TLS 터널\ninclude /etc/nginx/conf.d-stream/*.conf;\n' >> /etc/nginx/nginx.conf
fi

nginx -t

echo "== 5. 서비스 등록"
install -m 0644 "$SCRIPT_DIR/systemd/uvncrepeater.service" /etc/systemd/system/
install -m 0644 "$SCRIPT_DIR/systemd/remotehelp-websockify.service" /etc/systemd/system/
install -m 0644 "$SCRIPT_DIR/logrotate/remotehelp" /etc/logrotate.d/remotehelp

systemctl daemon-reload
systemctl enable --now uvncrepeater.service
systemctl enable --now remotehelp-websockify.service
systemctl reload nginx || systemctl restart nginx

echo "== 6. 방화벽"
ufw allow 22/tcp  || true
ufw allow "$RELAY_HTTPS_PORT"/tcp || true
ufw allow 5501/tcp || true
ufw --force enable || true

echo
echo "설치가 끝났습니다."
echo "  런처 TLS 터널 : $RELAY_SERVER_NAME:5501"
echo "  뷰어 웹소켓   : wss://$RELAY_SERVER_NAME:$RELAY_HTTPS_PORT/ws"
echo "  점검          : systemctl status uvncrepeater remotehelp-websockify nginx"
