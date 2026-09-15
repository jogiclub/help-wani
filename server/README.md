# 중계 서버 설치와 점검

Ubuntu 24.04 LTS 기준입니다. 도커로 개발/검증만 할 경우 저장소 최상위의 `docker-compose.yml` 을 쓰면 됩니다.

## 구성

```
고객 PC 에이전트 --wss /agent--+
                               |--> nginx(TLS 종료) --> Node 중계 서버(127.0.0.1:8081)
상담원 브라우저  --wss /viewer--+                              |
                                                    포털 /api/relay/auth (토큰 검증)
```

- 외부에 열리는 포트는 **443 하나**입니다.
- Node 중계 서버는 루프백에만 바인딩되며, 세션 짝이 맞는 두 연결 사이에서 바이너리를 그대로 전달합니다.
- 화면 데이터를 해석하거나 저장하지 않습니다.

## 설치

```bash
sudo RELAY_SERVER_NAME=relay.example.com \
     PORTAL_ORIGIN=https://portal.example.com \
     RELAY_AUTH_SHARED_SECRET="$(openssl rand -hex 24)" \
     CERTBOT_EMAIL=admin@example.com \
     ./install.sh
```

`RELAY_AUTH_SHARED_SECRET` 은 포털의 `.env` 와 같은 값이어야 합니다.
Let's Encrypt 를 쓰지 않으려면 `LETSENCRYPT=0` 을 추가합니다(자체 서명 인증서 생성).

## 점검 명령

```bash
systemctl status remotehelp-relay nginx
ss -ltnp | grep -E '8081|443'
curl https://relay.example.com/healthz          # {"status":"ok","sessions":N,"paired":N}
journalctl -u remotehelp-relay -f
tail -f /var/log/remotehelp/relay.log
```

`ss` 결과에서 8081 은 반드시 `127.0.0.1` 바인딩이어야 합니다.

## 프로토콜 확인용 도구

`scripts/viewer-probe.py` 는 브라우저 없이 상담원 뷰어 입장에서 접속해
화면 수신과 입력 전송을 확인합니다.

```bash
pip install websockets pillow
python3 scripts/viewer-probe.py \
    --url wss://relay.example.com/viewer \
    --token <1회용 뷰어 토큰> --frames 3
```

## 설정값

`/etc/remotehelp/relay.env` 에서 바꿀 수 있습니다.

| 변수 | 기본값 | 설명 |
|---|---|---|
| `RELAY_PORT` | 8081 | Node 중계 서버 포트(루프백) |
| `PORTAL_ORIGIN` | - | 토큰 검증을 요청할 포털 주소 |
| `RELAY_AUTH_SHARED_SECRET` | - | 포털과 공유하는 비밀값 |
| `RELAY_MAX_SESSIONS` | 200 | 동시 세션 상한 |
| `RELAY_MAX_FRAME_BYTES` | 4MB | 프레임 하나의 최대 크기 |
| `RELAY_PEER_GRACE_MS` | 15000 | 에이전트가 끊긴 뒤 재접속을 기다리는 시간 |
| `RELAY_LOG_LEVEL` | info | error / warn / info / debug |

## 주의 사항

- 프로토콜은 `docs/protocol.md` 를 따릅니다. 중계 서버는 바이너리 내용을 해석하지 않으므로
  프로토콜이 바뀌어도 중계 서버는 수정할 필요가 없습니다.
- 뷰어 토큰은 1회용입니다. 중계 서버가 포털에 검증을 요청하는 시점에 소진됩니다.
