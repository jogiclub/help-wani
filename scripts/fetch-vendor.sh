#!/bin/bash
# 파일 위치: scripts/fetch-vendor.sh
# 역할: 외부 프런트엔드 의존성(noVNC)을 내려받아 portal/assets/vendor 에 배치
set -e

NOVNC_VERSION="${NOVNC_VERSION:-1.7.0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/portal/assets/vendor/novnc"

if [ -f "$DEST/core/rfb.js" ]; then
    echo "[fetch-vendor] noVNC 가 이미 존재합니다: $DEST"
    exit 0
fi

echo "[fetch-vendor] noVNC v$NOVNC_VERSION 내려받는 중"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

curl -fsSL "https://github.com/novnc/noVNC/archive/refs/tags/v${NOVNC_VERSION}.tar.gz" -o "$TMP/novnc.tar.gz"
mkdir -p "$DEST"
tar xzf "$TMP/novnc.tar.gz" -C "$DEST" --strip-components=1

# 웹에서 필요한 것은 core/ 와 vendor/ 뿐이다.
rm -rf "$DEST/tests" "$DEST/docs" "$DEST/utils" "$DEST/snap" "$DEST/po"

echo "[fetch-vendor] 완료: $DEST (noVNC v$NOVNC_VERSION)"
