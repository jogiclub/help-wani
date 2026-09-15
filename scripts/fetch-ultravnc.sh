#!/bin/bash
# 파일 위치: scripts/fetch-ultravnc.sh
# 역할: 공식 UltraVNC 배포본을 받아 런처가 쓰는 x64 파일만 launcher/vendor/ultravnc 에 배치
#
# 저장소에는 이미 동일한 파일이 포함되어 있다. 이 스크립트는 버전을 올리거나
# 배치된 파일이 공식 배포본과 같은지 다시 확인할 때 쓴다.
#
# 사용법:
#   ./scripts/fetch-ultravnc.sh              # 바이너리 배치
#   ./scripts/fetch-ultravnc.sh --with-source # GPL 대응 소스 스냅샷도 함께 내려받음
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/launcher/vendor/ultravnc"

UVNC_VERSION="${UVNC_VERSION:-1.8.3.0}"
UVNC_ZIP_URL="${UVNC_ZIP_URL:-https://uvnc.eu/download/1800/UltraVNC_1830.zip}"
UVNC_ZIP_SHA256="${UVNC_ZIP_SHA256:-11163a0b9b86321bf6403eb0cf84b81f7230ce32e6291377e1fe5beb987f7e4f}"
UVNC_SOURCE_COMMIT="${UVNC_SOURCE_COMMIT:-33cee1a22e84e395c2ed09ad94fb4ad0b2f9c94b}"

# 런처가 쓰는 최소 구성. MS-Logon(ldapauth, workgrpdomnt4, authadmin),
# DSM 플러그인(SecureVNCPlugin64.dsm), 가상 디스플레이 드라이버, 뷰어, 언어 DLL 은 제외한다.
FILES=(
    "x64/winvnc.exe"
    "x64/vnchooks.dll"
    "x64/logging.dll"
    "x64/logmessages.dll"
    "x64/authSSP.dll"
    "x64/ddengine64.dll"
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "[fetch-ultravnc] UltraVNC $UVNC_VERSION 내려받는 중"
curl -fsSL --max-time 600 -o "$TMP/uvnc.zip" "$UVNC_ZIP_URL"

ACTUAL="$(sha256sum "$TMP/uvnc.zip" | cut -d' ' -f1)"
if [ "$ACTUAL" != "$UVNC_ZIP_SHA256" ]; then
    echo "[fetch-ultravnc] 체크섬이 다릅니다." >&2
    echo "  기대: $UVNC_ZIP_SHA256" >&2
    echo "  실제: $ACTUAL" >&2
    echo "  버전을 올리는 중이라면 UVNC_ZIP_SHA256 을 새 값으로 갱신하세요." >&2
    exit 1
fi
echo "[fetch-ultravnc] 체크섬 확인 완료"

mkdir -p "$DEST"

python3 - "$TMP/uvnc.zip" "$DEST" "${FILES[@]}" <<'PY'
import hashlib, os, sys, zipfile

zip_path, dest = sys.argv[1], sys.argv[2]
wanted = sys.argv[3:]

z = zipfile.ZipFile(zip_path)
lines = []

for name in wanted:
    data = z.read(name)
    base = os.path.basename(name)
    with open(os.path.join(dest, base), 'wb') as f:
        f.write(data)
    digest = hashlib.sha256(data).hexdigest()
    lines.append(f"{digest}  {base}")
    print(f"  {len(data)/1048576:7.2f} MB  {base}")

with open(os.path.join(dest, 'SHA256SUMS'), 'w', encoding='utf-8') as f:
    f.write('\n'.join(lines) + '\n')
PY

echo "[fetch-ultravnc] 배치 완료: $DEST"

if [ "${1:-}" = "--with-source" ]; then
    SRC_OUT="$ROOT/dist/ultravnc-source"
    mkdir -p "$SRC_OUT"
    echo "[fetch-ultravnc] GPL 대응 소스 스냅샷 내려받는 중 (commit ${UVNC_SOURCE_COMMIT:0:12})"
    curl -fsSL --max-time 600 \
        -o "$SRC_OUT/ultravnc-$UVNC_VERSION-src.tar.gz" \
        "https://codeload.github.com/ultravnc/UltraVNC/tar.gz/$UVNC_SOURCE_COMMIT"
    echo "[fetch-ultravnc] 소스 저장: $SRC_OUT"
    echo "  이 파일을 공개 URL 에 올리고 launcher/THIRD_PARTY_NOTICES.md 의 주소를 갱신하세요."
fi
