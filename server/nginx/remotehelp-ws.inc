# 파일 위치: server/nginx/remotehelp-ws.inc
# 역할: 웹소켓 프록시 공통 설정 (설치 위치: /etc/nginx/remotehelp-ws.inc)
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

# 원격 세션은 길게 유지된다.
proxy_read_timeout  1h;
proxy_send_timeout  1h;

# 화면 프레임을 지연 없이 흘려보낸다.
proxy_buffering off;
tcp_nodelay on;
