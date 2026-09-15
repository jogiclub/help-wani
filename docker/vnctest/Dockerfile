# 파일 위치: docker/vnctest/Dockerfile
# 역할: 고객 PC 를 대신하는 검증용 컨테이너 (Xvfb + x11vnc + 런처 TLS 터널 모의 구현)
FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        xvfb \
        x11vnc \
        xterm \
        x11-apps \
        openbox \
        python3 \
        ca-certificates \
        procps \
    && rm -rf /var/lib/apt/lists/*

COPY docker/vnctest/sc_shim.py /usr/local/bin/sc_shim.py
COPY docker/vnctest/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh /usr/local/bin/sc_shim.py

CMD ["/usr/local/bin/start.sh"]
