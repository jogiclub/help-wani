"""
파일 위치: backend/app/core/templates.py
역할: Jinja2 화면 렌더링 (PHP MY_Controller::render() 와 views/ 대응)

- 템플릿은 PHP views/ 와 같은 폴더 구조로 app/templates/ 에 둔다(views/auth/login.php → templates/auth/login.html).
- 레이아웃이 content_view 를 include 하는 PHP 방식을 그대로 쓴다. render(request, 'auth/login', data, 'layouts/blank').
- 자동 이스케이프를 켠다({{ x }} = html_escape). 이미 만든 HTML 은 Markup 으로 넘긴다(guide_text_html 등).
- 정의되지 않은 변수는 오류로 낸다(StrictUndefined). PHP 의 isset() 은 `x is defined` 로 옮긴다.
- <script> 안에 값을 넣을 때는 |js 필터(PHP json_encode 와 같은 결과)를 쓴다.
"""
from pathlib import Path

from fastapi.responses import HTMLResponse
from jinja2 import Environment, FileSystemLoader, StrictUndefined, select_autoescape
from markupsafe import Markup

from app import config
from app.core import csrf
from app.core.i18n import (current_locale, current_timezone, lang_text, load_messages, supported_locales,
                           timezone_offset_label, to_timezone)
from app.core.php import php_date, php_json_encode
from app.core.request import base_url, uri_string
from app.helpers.app import (asset_url, customer_url, format_duration, guide_image_url, guide_text_html,
                             relay_viewer_ws_url, status_label)

TEMPLATE_DIR = Path(__file__).resolve().parent.parent / 'templates'

env = Environment(
    loader=FileSystemLoader(str(TEMPLATE_DIR)),
    autoescape=select_autoescape(['html']),
    undefined=StrictUndefined,
    auto_reload=True,
    keep_trailing_newline=True,
)


def _js(value, unescaped_unicode=True):
    """<script> 안에 넣는 값. PHP json_encode($v, JSON_UNESCAPED_UNICODE) 와 같은 문자열."""
    return Markup(php_json_encode(value, unescaped_unicode=unescaped_unicode))


env.filters['js'] = _js
env.globals.update(
    base_url=base_url,
    asset_url=asset_url,
    customer_url=customer_url,
    relay_viewer_ws_url=relay_viewer_ws_url,
    uri_string=uri_string,
    lang_text=lang_text,
    to_timezone=to_timezone,
    timezone_offset_label=timezone_offset_label,
    guide_image_url=guide_image_url,
    guide_text_html=guide_text_html,
    format_duration=format_duration,
    status_label=status_label,
    php_date=php_date,
    csrf_token_name=csrf.TOKEN_NAME,
)


def render(request, view, data=None, layout='layouts/main', status=200):
    """레이아웃과 함께 화면을 그린다. PHP render() 가 넘기던 값(content_view, app_name, i18n)을 같이 넘긴다."""
    locale = current_locale()
    locales = supported_locales()

    context = dict(data or {})
    context.update(
        request=request,
        session=request.session,
        content_view=view + '.html',
        app_name=config.APP_NAME,
        csrf_hash=csrf.csrf_hash(request),
        i18n={
            'locale': locale,
            'intl': locales[locale]['intl'] if locale in locales else 'ko-KR',
            'timezone': current_timezone(),
            'messages': load_messages(locale),
        },
    )

    html = env.get_template(layout + '.html').render(context)
    return HTMLResponse(html, status_code=status)
