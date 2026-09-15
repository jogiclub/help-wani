#!/bin/bash
# 파일 위치: scripts/e2e-test.sh
# 역할: 포털 API → 리피터 → 뷰어 토큰까지 이어지는 흐름을 도커 환경에서 자동 점검
set -e

BASE="${BASE:-http://localhost:8099}"
RELAY="${RELAY:-https://localhost:8443}"
EMAIL="${EMAIL:-admin@demo.local}"
PASSWORD="${PASSWORD:-Test1234!admin}"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

say() { echo; echo "=== $1"; }

say "1. 로그인 (CSRF 토큰 취득)"
LOGIN_PAGE="$(curl -s -c "$JAR" "$BASE/login")"
CSRF_NAME="$(echo "$LOGIN_PAGE" | grep -oP 'name="csrf-name" content="\K[^"]+')"
CSRF_HASH="$(echo "$LOGIN_PAGE" | grep -oP 'name="csrf-hash" content="\K[^"]+')"
echo "csrf: $CSRF_NAME"

LOGIN_RES="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/login" \
    -d "email=$EMAIL" -d "password=$PASSWORD" -d "$CSRF_NAME=$CSRF_HASH")"
echo "$LOGIN_RES"
echo "$LOGIN_RES" | grep -q '"result":true' || { echo "로그인 실패"; exit 1; }

say "2. 세션 생성 (코드 발급)"
CREATE_RES="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/console/api/session/create" \
    -d "$CSRF_NAME=$CSRF_HASH")"
echo "$CREATE_RES"
CODE="$(echo "$CREATE_RES" | grep -oP '"code":"\K[0-9]{6}')"
SESSION_ID="$(echo "$CREATE_RES" | grep -oP '"session_id":\K[0-9]+')"
echo "code=$CODE session_id=$SESSION_ID"

say "3. 런처 코드 검증"
VERIFY_RES="$(curl -s -X POST "$BASE/api/launcher/verify" \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE\",\"pc_name\":\"E2E-TEST-PC\",\"os_version\":\"Windows 11 23H2\",\"launcher_version\":\"1.0.0\"}")"
echo "$VERIFY_RES"
REPEATER_ID="$(echo "$VERIFY_RES" | grep -oP '"repeater_id":"\K[0-9]+')"
VNC_PASSWORD="$(echo "$VERIFY_RES" | grep -oP '"vnc_password":"\K[^"]+')"
SECRET="$(echo "$VERIFY_RES" | grep -oP '"launcher_secret":"\K[a-f0-9]+')"
echo "repeater_id=$REPEATER_ID"

say "4. 같은 코드 재사용 차단 확인"
curl -s -X POST "$BASE/api/launcher/verify" -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE\"}" | head -c 200; echo

say "5. 런처 상태 보고 (waiting)"
curl -s -X POST "$BASE/api/launcher/status" -H 'Content-Type: application/json' \
    -d "{\"session_id\":$SESSION_ID,\"launcher_secret\":\"$SECRET\",\"status\":\"waiting\"}"; echo

say "6. 고객 PC 모의 컨테이너 기동 (리피터 역방향 접속)"
docker compose --profile test rm -sf vnctest >/dev/null 2>&1 || true
REPEATER_ID="$REPEATER_ID" VNC_PASSWORD="$VNC_PASSWORD" \
    docker compose --profile test up -d vnctest
sleep 6
docker compose --profile test logs vnctest --tail 8

say "7. 뷰어 토큰 발급"
TOKEN_RES="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/console/api/session/viewer-token" \
    -d "session_id=$SESSION_ID" -d "$CSRF_NAME=$CSRF_HASH")"
echo "$TOKEN_RES"
TOKEN="$(echo "$TOKEN_RES" | grep -oP '"token":"\K[a-f0-9]{64}')"

say "8. 잘못된 토큰으로 /ws 접근 (403 이어야 함)"
curl -sk -o /dev/null -w "status=%{http_code}\n" \
    -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
    "$RELAY/ws?token=0000000000000000000000000000000000000000000000000000000000000000"

say "9. 정상 토큰으로 /ws 접근 (101 이어야 함)"
curl -sk -o /dev/null -w "status=%{http_code}\n" --max-time 5 \
    -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
    "$RELAY/ws?token=$TOKEN" || true

say "10. 토큰 재사용 차단 확인 (403 이어야 함)"
curl -sk -o /dev/null -w "status=%{http_code}\n" --max-time 5 \
    -H "Connection: Upgrade" -H "Upgrade: websocket" \
    -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
    "$RELAY/ws?token=$TOKEN"

say "11. 상담원 종료 후 런처 heartbeat 의 terminate 확인"
curl -s -b "$JAR" -X POST "$BASE/console/api/session/end" \
    -d "session_id=$SESSION_ID" -d "$CSRF_NAME=$CSRF_HASH"; echo
curl -s "$BASE/api/launcher/heartbeat?session_id=$SESSION_ID&launcher_secret=$SECRET"; echo

say "12. 정리"
docker compose --profile test rm -sf vnctest >/dev/null 2>&1 || true
echo "완료"
