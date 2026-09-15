#!/bin/bash
# 파일 위치: scripts/e2e-test.sh
# 역할: 포털 API -> 중계 서버 -> 화면 수신까지 이어지는 흐름을 도커 환경에서 자동 점검
set -e

BASE="${BASE:-http://localhost:8099}"
RELAY_HOSTNAME="${RELAY_HOSTNAME:-localhost}"
RELAY_PORT="${RELAY_PORT:-8443}"
RELAY="${RELAY:-https://$RELAY_HOSTNAME:$RELAY_PORT}"
EMAIL="${EMAIL:-admin@demo.local}"
PASSWORD="${PASSWORD:-Test1234!admin}"
JAR="$(mktemp)"

cleanup() {
    rm -f "$JAR"
    docker compose --profile test rm -sf agentsim >/dev/null 2>&1 || true
}
trap cleanup EXIT

say() { echo; echo "=== $1"; }

say "1. 로그인 (CSRF 토큰 취득)"
LOGIN_PAGE="$(curl -s -c "$JAR" "$BASE/login")"
CSRF_NAME="$(echo "$LOGIN_PAGE" | grep -oP 'name="csrf-name" content="\K[^"]+')"
CSRF_HASH="$(echo "$LOGIN_PAGE" | grep -oP 'name="csrf-hash" content="\K[^"]+')"

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

say "3. 런처 코드 검증 (에이전트 토큰 발급)"
VERIFY_RES="$(curl -s -X POST "$BASE/api/launcher/verify" \
    -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE\",\"pc_name\":\"E2E-TEST-PC\",\"os_version\":\"Windows 11 23H2\",\"launcher_version\":\"1.0.0\"}")"
echo "$VERIFY_RES"
AGENT_TOKEN="$(echo "$VERIFY_RES" | grep -oP '"agent_token":"\K[a-f0-9]{64}')"
SECRET="$(echo "$VERIFY_RES" | grep -oP '"launcher_secret":"\K[a-f0-9]{64}')"

say "4. 같은 코드 재사용 차단 확인"
curl -s -X POST "$BASE/api/launcher/verify" -H 'Content-Type: application/json' \
    -d "{\"code\":\"$CODE\"}" | head -c 200; echo

say "5. 런처 상태 보고 (waiting)"
curl -s -X POST "$BASE/api/launcher/status" -H 'Content-Type: application/json' \
    -d "{\"session_id\":$SESSION_ID,\"launcher_secret\":\"$SECRET\",\"status\":\"waiting\"}"; echo

say "6. 고객 PC 에이전트 기동 (중계 서버 접속)"
docker compose --profile test rm -sf agentsim >/dev/null 2>&1 || true
AGENT_TOKEN="$AGENT_TOKEN" docker compose --profile test up -d agentsim
sleep 4
docker compose --profile test logs agentsim --tail 5

say "7. 뷰어 토큰 발급"
TOKEN_RES="$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE/console/api/session/viewer-token" \
    -d "session_id=$SESSION_ID" -d "$CSRF_NAME=$CSRF_HASH")"
echo "$TOKEN_RES"
TOKEN="$(echo "$TOKEN_RES" | grep -oP '"token":"\K[a-f0-9]{64}')"

say "8. 잘못된 토큰으로 뷰어 접속 (거절되어야 함)"
docker compose --profile test run --rm --entrypoint python3 agentsim \
    /usr/local/bin/viewer-probe.py --url "wss://relay:8443/viewer" \
    --token 0000000000000000000000000000000000000000000000000000000000000000 \
    --insecure --seconds 5 2>&1 | tail -3 || echo "   (예상대로 실패)"

say "9. 정상 토큰으로 화면 수신 확인"
docker compose --profile test run --rm --entrypoint python3 agentsim \
    /usr/local/bin/viewer-probe.py --url "wss://relay:8443/viewer" \
    --token "$TOKEN" --insecure --frames 3 --seconds 20

say "10. 에이전트가 받은 입력 이벤트 확인"
docker compose --profile test logs agentsim --tail 12 | grep "입력 수신" || echo "   입력 로그 없음"

say "11. 같은 뷰어 토큰 재사용 차단 확인"
docker compose --profile test run --rm --entrypoint python3 agentsim \
    /usr/local/bin/viewer-probe.py --url "wss://relay:8443/viewer" \
    --token "$TOKEN" --insecure --seconds 5 2>&1 | tail -3 || echo "   (예상대로 실패)"

say "12. 상담원 종료 후 런처 heartbeat 의 terminate 확인"
curl -s -b "$JAR" -X POST "$BASE/console/api/session/end" \
    -d "session_id=$SESSION_ID" -d "$CSRF_NAME=$CSRF_HASH"; echo
curl -s "$BASE/api/launcher/heartbeat?session_id=$SESSION_ID&launcher_secret=$SECRET"; echo

say "13. 중계 서버 상태"
curl -sk "$RELAY/healthz"; echo

echo
echo "완료"
