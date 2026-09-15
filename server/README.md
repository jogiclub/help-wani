# 중계 서버 설치와 점검

Ubuntu 24.04 LTS 기준입니다. 도커로 개발/검증만 할 경우 저장소 최상위의 `docker-compose.yml` 을 쓰면 됩니다.

## 구성

```
고객 런처 --TLS 5501--> nginx stream --> 127.0.0.1:5500 uvncrepeater(서버측)
                                                          |
상담원 브라우저 --wss 443/ws--> nginx http --auth_request--> 포털 /api/relay/auth
                                   |
                                   +--> 127.0.0.1:6080 websockify --> 127.0.0.1:5900 uvncrepeater(뷰어측)
```

리피터의 두 포트(5500, 5900)는 `127.0.0.1` 에만 바인딩되며 외부에 직접 노출되지 않습니다.

## 설치

```bash
sudo RELAY_SERVER_NAME=relay.example.com \
     PORTAL_UPSTREAM=portal.example.com:80 \
     PORTAL_HOST=portal.example.com \
     RELAY_AUTH_SHARED_SECRET="$(openssl rand -hex 24)" \
     CERTBOT_EMAIL=admin@example.com \
     ./install.sh
```

`RELAY_AUTH_SHARED_SECRET` 은 포털의 `.env` 와 동일한 값이어야 합니다.
Let's Encrypt 를 쓰지 않으려면 `LETSENCRYPT=0` 을 추가합니다(자체 서명 인증서 생성).

## 점검 명령

```bash
systemctl status uvncrepeater remotehelp-websockify nginx
ss -ltnp | grep -E '5500|5900|6080|5501|443'
curl -k https://relay.example.com/healthz
tail -f /var/log/remotehelp/repeater.log
tail -f /var/log/remotehelp/ws-access.log
```

`ss` 결과에서 5500/5900/6080 은 반드시 `127.0.0.1` 바인딩이어야 합니다.

## 프로토콜 확인용 도구

`scripts/rfb-probe.py` 는 브라우저 없이 뷰어 입장에서 접속해 리피터 매칭, VNC 인증,
화면 갱신 수신까지 확인합니다.

```bash
# 리피터에 직접(서버 내부에서)
python3 scripts/rfb-probe.py --host 127.0.0.1 --port 5900 \
    --repeater-id 123456789 --password 8자리비번

# 실제 운영 경로(wss -> websockify -> 리피터)
python3 scripts/rfb-probe.py --host relay.example.com --port 443 \
    --ws-token <1회용 뷰어 토큰> --repeater-id 123456789 --password 8자리비번
```

## 주의 사항

- 리피터 ID 는 **양의 10진 정수**여야 합니다(`uvncrepeater` 의 `parseId()` 가 `strtol` 로 해석).
- 리피터 설정은 Mode 2 만 허용(`allowedmodes = 2`)합니다. Mode 1 은 임의 주소로 중계가 가능하므로 사용하지 않습니다.
- 인증서 갱신 시 `stream` 리스너까지 새 인증서를 읽도록 `systemctl reload nginx` 훅이 등록됩니다.
