#!/bin/bash
# 파일 위치: docker/vnctest/start.sh
# 역할: 검증용 가상 데스크톱 기동 후 sc_shim 으로 리피터에 역방향 접속
set -e

export DISPLAY=:1
VNC_PASSWORD="${VNC_PASSWORD:-Test1234}"
REPEATER_ID="${REPEATER_ID:-123456789}"
RELAY_HOST="${RELAY_HOST:-relay}"
RELAY_PORT="${RELAY_PORT:-5501}"

Xvfb :1 -screen 0 1280x800x24 &
sleep 2
openbox &
xterm -geometry 100x30+40+40 -T "RemoteHelp 검증용 화면" &

x11vnc -display :1 -rfbport 5901 -localhost -passwd "$VNC_PASSWORD" \
       -forever -shared -noxdamage -quiet &
sleep 2

exec python3 /usr/local/bin/sc_shim.py \
    --relay-host "$RELAY_HOST" --relay-port "$RELAY_PORT" \
    --repeater-id "$REPEATER_ID" --vnc-port 5901 --insecure
