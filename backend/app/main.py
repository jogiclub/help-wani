"""
파일 위치: backend/app/main.py
역할: FastAPI 포털 앱 생성, 미들웨어·예외 처리·경로 등록, 상태 확인(/healthz)

PHP 포털(web)과 나란히 띄워 같은 DB 를 쓴다. 전환 계획은 docs/fastapi-migration-plan.md.
라우터를 더할 때 조직 코드를 받는 라우터({org_code})는 반드시 맨 마지막에 등록한다(CLAUDE.md 3.8).
"""
import logging

from fastapi import Depends, FastAPI, Request
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.middleware.sessions import SessionMiddleware

from app import config, db
from app.core import session as rh_session
from app.core.csrf import CSRFMiddleware, verify_csrf
from app.core.errors import Halt, show_404
from app.core.middleware import RequestContextMiddleware
from app.core.response import api_response
from app.routers import api_launcher, api_realtime_auth, api_relay, auth, customer, dev, guide, home
from app.static import AssetFiles

logger = logging.getLogger('remotehelp')

app = FastAPI(
    title=config.APP_NAME,
    # 자동 문서 화면은 개발 환경에서만 연다.
    docs_url='/_docs' if config.APP_ENV == 'development' else None,
    redoc_url=None,
    openapi_url='/_openapi.json' if config.APP_ENV == 'development' else None,
    # 제외 경로가 아닌 모든 POST 에 CSRF 검사(CI3 csrf_protection = TRUE)
    dependencies=[Depends(verify_csrf)],
    # 끝 / 는 RequestContextMiddleware 가 CI3 처럼 무시하므로 리다이렉트하지 않는다.
    redirect_slashes=False,
)

# 나중에 더한 미들웨어가 바깥쪽이다. 세션 → CSRF → 요청 문맥(세션을 읽음) 순서로 감싼다.
app.add_middleware(RequestContextMiddleware)
app.add_middleware(CSRFMiddleware)
app.add_middleware(SessionMiddleware, **rh_session.middleware_options())


@app.exception_handler(Halt)
async def halt_handler(request: Request, exc: Halt):
    """raise Halt(응답) 으로 멈춘 요청은 그 응답을 그대로 돌려준다(CI3 _display(); exit; 대응)."""
    return exc.response


@app.exception_handler(StarletteHTTPException)
async def http_error_handler(request: Request, exc: StarletteHTTPException):
    """없는 경로는 CI3 404 화면과 같게."""
    if exc.status_code == 404:
        return show_404()
    return api_response(False, str(exc.detail), None, exc.status_code)


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


app.include_router(api_launcher.router)
app.include_router(api_relay.router)
app.include_router(api_realtime_auth.router)
app.include_router(auth.router)
app.include_router(home.router)

if config.APP_ENV == 'development':
    app.include_router(dev.router)

# 조직 코드로 시작하는 주소. 공개 가이드의 모음 주소가 글 주소보다 먼저다(routers/guide.py 안의 순서).
app.include_router(guide.router)
app.include_router(customer.router)

# 조직 코드 하나만 받는 /{org_code} 는 반드시 맨 마지막이다(CLAUDE.md 3.8). 새 최상위 주소는 이보다 위에 등록한다.
app.include_router(customer.org_router)
