"""
파일 위치: backend/app/main.py
역할: FastAPI 포털 앱 생성, 정적 파일 연결, 상태 확인(/healthz)

PHP 포털(web)과 나란히 띄워 같은 DB 를 쓴다. 전환 계획은 docs/fastapi-migration-plan.md.
라우터를 더할 때 조직 코드를 받는 라우터({org_code})는 반드시 맨 마지막에 등록한다(CLAUDE.md 3.8).
"""
import logging

from fastapi import FastAPI

from app import config, db
from app.core.response import api_response
from app.static import AssetFiles

logger = logging.getLogger('remotehelp')

app = FastAPI(
    title=config.APP_NAME,
    # 자동 문서 화면은 개발 환경에서만 연다.
    docs_url='/_docs' if config.APP_ENV == 'development' else None,
    redoc_url=None,
    openapi_url='/_openapi.json' if config.APP_ENV == 'development' else None,
)

app.mount('/assets', AssetFiles(directory=config.ASSETS_DIR), name='assets')


@app.get('/healthz')
def healthz():
    """앱과 DB 연결 상태. DB 에 붙지 못하면 503 을 돌려준다."""
    try:
        info = db.ping()
    except Exception:
        logger.exception('DB 연결 실패')
        return api_response(False, 'DB 연결 실패', {'db': False}, 503)

    return api_response(True, '', {'db': True, **info})
