"""
파일 위치: backend/app/libraries/chat_greeting.py
역할: 상담 위젯 인사말 — 바로가기 버튼 검사, 변수({visitor_name} {org_name}) 채우기

인사말은 위젯을 열 때 화면에만 그리고, 방문자가 첫 메시지를 보낼 때 시스템 메시지(meta.type = greeting)로 한 번 저장한다(CLAUDE.md 3.18).
위젯은 textContent 로 그리므로 HTML 을 받지 않고 글자 그대로 둔다.
"""
import json
import re

from app.core.i18n import lang_text
from app.core.php import PCRE_U_SPACE, php_str
from app.helpers.php_filter import validate_https_url

BODY_MAX = 1000
BUTTON_MAX = 3
LABEL_MAX = 30
URL_MAX = 500

# 인사말에 쓸 수 있는 변수. 매크로 변수와 같은 문법이다.
VARIABLES = ('visitor_name', 'org_name')


def _is_https_url(url):
    url = php_str(url)
    return len(url) <= URL_MAX and re.match(r'^https://[^\s/?#]+[^\s]*$', url, re.IGNORECASE) is not None \
        and validate_https_url(url)


def clean_buttons(value):
    """관리 화면에서 받은 버튼 목록을 검사한다. 주소는 https 만 받는다. 잘못되면 오류 문구(str)."""
    if value == '' or value is None:
        return []

    if isinstance(value, (list, dict)):
        items = value
    else:
        try:
            items = json.loads(php_str(value))
        except ValueError:
            items = None
    if isinstance(items, dict):
        items = list(items.values())
    if not isinstance(items, list):
        return '바로가기 버튼 형식이 올바르지 않습니다.'

    out = []
    for item in items:
        item = item if isinstance(item, dict) else {}
        label = re.sub(PCRE_U_SPACE + '+', ' ', php_str(item.get('label', ''))).strip(' \t\n\r\x00\x0b')
        url = php_str(item.get('url', '')).strip(' \t\n\r\x00\x0b')

        if label == '' and url == '':
            continue
        if label == '' or len(label) > LABEL_MAX:
            return f'버튼 이름은 1~{LABEL_MAX}자로 적어 주세요.'
        if not _is_https_url(url):
            return f'버튼 주소는 https:// 로 시작하는 주소만 쓸 수 있습니다. ({label})'

        out.append({'label': label, 'url': url})

    if len(out) > BUTTON_MAX:
        return f'바로가기 버튼은 {BUTTON_MAX}개까지 둘 수 있습니다.'

    return out


def buttons(org):
    """저장된 버튼 목록. 저장 뒤에 규칙이 바뀌었을 수 있으므로 꺼낼 때도 주소를 다시 확인한다."""
    try:
        items = json.loads(php_str(org.get('chat_greeting_buttons')))
    except ValueError:
        items = None
    if isinstance(items, dict):
        items = list(items.values())
    if not isinstance(items, list):
        return []
    return [item for item in items
            if isinstance(item, dict) and item.get('label') is not None and item.get('url') is not None
            and _is_https_url(item['url'])]


def render(org, visitor):
    """방문자에게 보일 인사말. 비어 있으면 None."""
    body = php_str(org.get('chat_greeting')).strip(' \t\n\r\x00\x0b')
    if body == '':
        return None

    name = php_str(visitor.get('name') if visitor else '').strip(' \t\n\r\x00\x0b')

    # 이름이 없을 때의 호칭은 고객이 읽으므로 조직 언어로 만든다(CLAUDE.md 3.2, 매크로와 같은 문구).
    # PHP strtr(배열): 한 번 훑으며 바꾸고, 바꾼 결과는 다시 바꾸지 않는다.
    replacements = {
        '{visitor_name}': name if name != '' else lang_text('macro.visitor_fallback', {}, org.get('locale')),
        '{org_name}': php_str(org.get('name')),
    }
    body = re.sub('|'.join(re.escape(k) for k in sorted(replacements, key=len, reverse=True)),
                  lambda m: replacements[m.group(0)], body)

    return {'body': body, 'buttons': buttons(org)}


def unknown_variables(body):
    """모르는 변수. 저장은 막지 않고 경고만 한다."""
    found = re.findall(r'\{([a-z_]+)\}', php_str(body))
    out = []
    for name in found:
        if name not in VARIABLES and name not in out:
            out.append(name)
    return out
