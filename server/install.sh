#!/bin/bash
# 파일 위치: server/install.sh
# 역할: Ubuntu 24.04 중계 서버에 Node.js 중계 서버와 nginx(TLS 종료)를 설치하고 서비스로 등록
#
# 사용법:
#   sudo RELAY_SERVER_NAME=relay.example.com \
#        PORTAL_ORIGIN=https://portal.example.com \
#        RELAY_AUTH_SHARED_SECRET=... \
#        ./install.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

RELAY_SERVER_NAME="${RELAY_SERVER_NAME:-}"
PORTAL_ORIGIN="${PORTAL_ORIGIN:-}"
RELAY_AUTH_SHARED_SECRET="${RELAY_AUTH_SHARED_SECRET:-}"
RELAY_HTTPS_PORT="${RELAY_HTTPS_PORT:-443}"
RELAY_UPSTREAM="${RELAY_UPSTREAM:-127.0.0.1:8081}"
LETSENCRYPT="${LETSENCRYPT:-1}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-}"
INSTALL_DIR=/opt/remotehelp/relay

if [ "$(id -u)" -ne 0 ]; then
    echo "root 권한으로 실행해 주세요." >&2
    exit 1
fi

for v in RELAY_SERVER_NAME PORTAL_ORIGIN RELAY_AUTH_SHARED_SECRET; do
    if [ -z "${!v}" ]; then
        echo "환경변수 $v 가 필요합니다." >&2
        exit 1
    fi
done

echo "== 1. 패키지 설치"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx gettext-base openssl ufw logrotate ca-certificates curl

if ! command -v node >/dev/null 2>&1 || [ "$(node -v | cut -c2- | cut -d. -f1)" -lt 20 ]; then
    echo "== 1-1. Node.js 20 설치"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

echo "== 2. 중계 서버 배치"
id -u remotehelp >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d /opt/remotehelp remotehelp

mkdir -p "$INSTALL_DIR"
cp -r "$REPO_DIR/relay/src" "$INSTALL_DIR/"
cp "$REPO_DIR/relay/package.json" "$INSTALL_DIR/"
[ -f "$REPO_DIR/relay/package-lock.json" ] && cp "$REPO_DIR/relay/package-lock.json" "$INSTALL_DIR/"

cd "$INSTALL_DIR"
npm install --omit=dev --no-audit --no-fund
chown -R remotehelp:remotehelp /opt/remotehelp

mkdir -p /var/log/remotehelp
chown remotehelp:adm /var/log/remotehelp
chmod 0750 /var/log/remotehelp

echo "== 3. 환경설정"
mkdir -p /etc/remotehelp
cat > /etc/remotehelp/relay.env <<ENVFILE
RELAY_HOST=127.0.0.1
RELAY_PORT=8081
PORTAL_ORIGIN=$PORTAL_ORIGIN
RELAY_AUTH_SHARED_SECRET=$RELAY_AUTH_SHARED_SECRET
RELAY_LOG_LEVEL=info
RELAY_MAX_SESSIONS=200
ENVFILE
chmod 0640 /etc/remotehelp/relay.env
chown root:remotehelp /etc/remotehelp/relay.env

echo "== 4. 인증서 준비"
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

echo "== 5. nginx 설정"
export RELAY_SERVER_NAME RELAY_HTTPS_PORT RELAY_UPSTREAM
SUBST='${RELAY_SSL_CERT} ${RELAY_SSL_KEY} ${RELAY_SERVER_NAME} ${RELAY_HTTPS_PORT} ${RELAY_UPSTREAM}'

install -m 0644 "$SCRIPT_DIR/nginx/remotehelp-ws.inc" /etc/nginx/remotehelp-ws.inc
envsubst "$SUBST" < "$SCRIPT_DIR/nginx/remotehelp.conf" > /etc/nginx/sites-available/remotehelp.conf
ln -sf /etc/nginx/sites-available/remotehelp.conf /etc/nginx/sites-enabled/remotehelp.conf
rm -f /etc/nginx/sites-enabled/default

nginx -t

echo "== 6. 서비스 등록"
install -m 0644 "$SCRIPT_DIR/systemd/remotehelp-relay.service" /etc/systemd/system/
install -m 0644 "$SCRIPT_DIR/logrotate/remotehelp" /etc/logrotate.d/remotehelp

systemctl daemon-reload
systemctl enable --now remotehelp-relay.service
systemctl reload nginx || systemctl restart nginx

echo "== 7. 방화벽"
ufw allow 22/tcp || true
ufw allow "$RELAY_HTTPS_PORT"/tcp || true
ufw --force enable || true

echo
echo "설치가 끝났습니다."
echo "  에이전트 : wss://$RELAY_SERVER_NAME:$RELAY_HTTPS_PORT/agent"
echo "  뷰어     : wss://$RELAY_SERVER_NAME:$RELAY_HTTPS_PORT/viewer"
echo "  상태     : curl https://$RELAY_SERVER_NAME/healthz"
echo "  점검     : systemctl status remotehelp-relay nginx"
