"""
파일 위치: backend/app/static.py
역할: /assets 정적 파일 제공 (FastAPI 로 직접 열 때. 앞단 Apache 의 차단 규칙과 같게 막는다)
"""
import re

from fastapi.responses import PlainTextResponse
from fastapi.staticfiles import StaticFiles

# apache-vhost.conf 의 FilesMatch: 설정, 로그, SQL, 문서 파일은 어디서든 내주지 않는다.
_DENY_ANYWHERE = re.compile(r'\.(ini|log|sql|md)$', re.IGNORECASE)

# backend/assets/uploads/.htaccess: 업로드 폴더에서는 스크립트, HTML, SVG 를 내주지 않는다.
_DENY_UPLOADS = re.compile(r'\.(php[0-9]?|phtml|phar|cgi|pl|py|sh|html?|svg)$', re.IGNORECASE)


class AssetFiles(StaticFiles):
    """StaticFiles 에 앞단 Apache 와 같은 차단 규칙과 nosniff 헤더를 더한다."""

    async def get_response(self, path, scope):
        normalized = path.replace('\\', '/').lstrip('/')
        name = normalized.rsplit('/', 1)[-1]

        # 숨김 파일(.htaccess, .gitkeep)은 내주지 않는다(.ht* 는 Apache 기본 설정도 막는다).
        denied = (
            name.startswith('.')
            or _DENY_ANYWHERE.search(normalized)
            or (normalized.lower().startswith('uploads/') and _DENY_UPLOADS.search(normalized))
        )
        if denied:
            return PlainTextResponse('Forbidden', status_code=403)

        response = await super().get_response(path, scope)

        if normalized.lower().startswith('uploads/'):
            response.headers['X-Content-Type-Options'] = 'nosniff'

        return response
