"""
파일 위치: backend/app/routers/download.py
역할: 고객용 런처 실행 파일 다운로드 제공 (PHP controllers/Download.php 대응)
"""
import hashlib
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from fastapi.responses import FileResponse

from app import config
from app.core.php import php_date, php_round, php_str

# 배포 파일이 놓이는 곳 (빌드 산출물을 이 이름으로 올린다). PHP 와 같은 파일(portal/assets/downloads)을 본다.
LAUNCHER_FILE = 'RemoteHelp.exe'


def launcher_path():
    return config.ASSETS_DIR / 'downloads' / LAUNCHER_FILE


def _cached_hash(path):
    """해시는 파일이 크면 비용이 있으므로 임시 폴더에 저장해 둔다. 배포 파일이 바뀌면(수정 시각 변경) 다시 계산한다."""
    cache = Path(tempfile.gettempdir()) / f'remotehelp-launcher-{int(path.stat().st_mtime)}.sha256'
    try:
        return cache.read_text().strip()
    except OSError:
        pass

    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    value = digest.hexdigest()
    try:
        cache.write_text(value)
    except OSError:
        pass
    return value


def launcher_info():
    """배포 파일 정보. 파일이 없으면 None."""
    path = launcher_path()
    if not path.is_file():
        return None

    stat = path.stat()
    return {
        'size': stat.st_size,
        # PHP round(…, 1) 결과를 화면에 그대로 쓰면 12.0 이 "12" 로 나온다.
        'size_mb': php_str(php_round(stat.st_size / 1048576, 1)),
        'modified': php_date('Y-m-d', datetime.fromtimestamp(stat.st_mtime, timezone.utc)),
        'sha256': _cached_hash(path),
    }


def launcher_response(org):
    """조직 코드를 파일 이름에 담아 내려준다. 런처는 실행 파일 이름에서 조직 코드를 읽어 시작 화면에 반영한다."""
    filename = 'RemoteHelp_' + org.org_code + '.exe'
    return FileResponse(
        launcher_path(),
        media_type='application/octet-stream',
        headers={
            'Content-Disposition': f'attachment; filename="{filename}"',
            'Content-Transfer-Encoding': 'binary',
            'Cache-Control': 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options': 'nosniff',
        },
    )
