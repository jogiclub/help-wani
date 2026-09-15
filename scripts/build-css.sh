#!/bin/bash
# 파일 위치: scripts/build-css.sh
# 역할: Tailwind 독립 실행 CLI 로 portal/assets/css/app.css 를 생성
#
# Node.js 없이 동작한다. CLI 바이너리는 저장소에 포함하지 않고 필요할 때 내려받는다.
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TW_VERSION="${TW_VERSION:-v4.3.3}"
TW_BIN="${TW_BIN:-$ROOT/.tools/tailwindcss}"
WATCH=""

if [ "${1:-}" = "--watch" ]; then
    WATCH="--watch"
fi

if [ ! -x "$TW_BIN" ]; then
    echo "[build-css] Tailwind CLI $TW_VERSION 내려받는 중"
    mkdir -p "$(dirname "$TW_BIN")"
    curl -fsSL -o "$TW_BIN" \
        "https://github.com/tailwindlabs/tailwindcss/releases/download/$TW_VERSION/tailwindcss-linux-x64"
    chmod +x "$TW_BIN"
fi

"$TW_BIN" \
    -i "$ROOT/portal/assets/css/tailwind.src.css" \
    -o "$ROOT/portal/assets/css/app.css" \
    --minify $WATCH

echo "[build-css] 생성 완료: portal/assets/css/app.css"
