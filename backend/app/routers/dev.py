"""
파일 위치: backend/app/routers/dev.py
역할: 개발 환경 전용 확인 화면 (레이아웃 자리 표시 화면)

APP_ENV = development 일 때만 등록한다(main.py). 화면을 다 옮기면 지운다(docs/fastapi-migration-plan.md 9단계).
"""
from fastapi import APIRouter, Depends, Request

from app import config
from app.core.deps import current_agent, require_operator
from app.core.errors import Halt, show_404
from app.core.i18n import supported_locales
from app.core.templates import render

router = APIRouter(prefix='/_dev')

_PUBLIC_LAYOUTS = {'blank', 'customer', 'public', 'guide'}


def _placeholder(request, layout):
    data = {'page_title': f'레이아웃 확인 ({layout})', 'layout_name': layout}
    if layout == 'public':
        data.update(locales=supported_locales(), icon_names=['support_agent', 'language'],
                    contact_email=config.env('CONTACT_EMAIL', ''), contact_phone=config.env('CONTACT_PHONE', ''))
    if layout == 'guide':
        data.update(theme='card', accent='#2563eb')
    return render(request, '_dev/placeholder', data, 'layouts/' + layout)


@router.get('/layout/{layout}')
def layout_preview(request: Request, layout: str):
    if layout not in _PUBLIC_LAYOUTS:
        raise Halt(show_404())
    return _placeholder(request, layout)


@router.get('/layout-agent/main')
def layout_main(request: Request, agent=Depends(current_agent)):
    return _placeholder(request, 'main')


@router.get('/layout-agent/operator')
def layout_operator(request: Request, agent=Depends(require_operator)):
    return _placeholder(request, 'operator')
