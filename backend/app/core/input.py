"""
파일 위치: backend/app/core/input.py
역할: 요청 값을 PHP $_POST / $_GET 과 같은 모양으로 읽기 (CI3 input->post(), input->get(), json_input() 대응)

화면 JS(jQuery $.ajax)는 폼 인코딩으로 보내고 배열은 'ids[]', 중첩은 'hours[1][0][start]' 처럼 이름에 괄호를 쓴다.
PHP 는 이를 배열로 풀어 주므로 옮긴 코드가 같은 모양을 받도록 여기서 똑같이 푼다.
"""
import json
import re

from fastapi import Request
from starlette.datastructures import UploadFile

from app.core.security import remove_invisible_characters, xss_clean

_KEY_RE = re.compile(r'^([^\[\]]+)((?:\[[^\[\]]*\])*)$')


def _set_php_key(target, name, value):
    """'a[b][]' 같은 이름을 PHP 와 같이 중첩 dict 로 넣는다. 빈 괄호는 0, 1, 2 ... 순번 키(PHP 배열)."""
    # PHP 는 변수 이름 앞 공백을 지우고, 이름의 공백과 점을 '_' 로 바꾼다.
    name = name.lstrip(' ')
    match = _KEY_RE.match(name)
    if not match:
        target[name.replace(' ', '_').replace('.', '_')] = value
        return

    parts = [match.group(1).replace(' ', '_').replace('.', '_')] + re.findall(r'\[([^\[\]]*)\]', match.group(2))
    node = target
    for i, part in enumerate(parts):
        last = i == len(parts) - 1
        if part == '':
            # PHP 처럼 지금까지의 정수 키 중 가장 큰 값 + 1
            ints = [int(k) for k in node if isinstance(k, str) and k.lstrip('-').isdigit()]
            part = str(max(ints) + 1 if ints else 0)
        if last:
            node[part] = value
        else:
            child = node.get(part)
            if not isinstance(child, dict):
                child = {}
                node[part] = child
            node = child


def php_list(value):
    """PHP 배열(순번 키 dict)을 목록으로. 목록이 아니면 빈 목록."""
    if isinstance(value, list):
        return value
    if isinstance(value, dict):
        return list(value.values())
    return []


_CLEAN_KEY_RE = re.compile(r'^[a-z0-9:_/|-]+$', re.IGNORECASE)


def _clean_key_ok(key):
    """CI3 Input::_clean_input_keys(). PCRE 의 $ 는 끝 줄바꿈 앞에서도 맞는다(파이썬 $ 와 같음)."""
    return bool(_CLEAN_KEY_RE.match(key))


def _clean_input_data(value):
    """CI3 Input::_clean_input_data(): 제어 문자 제거, 배열이면 키를 검사해 새 배열로(나쁜 키는 0 으로 바뀜)."""
    if isinstance(value, dict):
        cleaned = {}
        for key, item in value.items():
            cleaned[key if _clean_key_ok(key) else '0'] = _clean_input_data(item)
        return cleaned
    return remove_invisible_characters(value, False)


def sanitize_globals(fields):
    """CI3 Input::_sanitize_globals() 의 $_GET/$_POST 처리. 요청 시작 때 모든 값에 적용된다.

    맞는 키는 정리한 값으로 바꾸고, 나쁜 키는 원래 값을 그대로 두면서 키 0 에 정리한 값을 넣는다
    (CI3 가 $_POST[FALSE] = ... 로 쓰는 동작 그대로).
    """
    for key in list(fields):
        value = fields[key]
        fields[key if _clean_key_ok(key) else '0'] = _clean_input_data(value)
    return fields


class PostData:
    """$_POST 대응. post('name') 은 문자열(없으면 기본값), 배열 이름은 dict(PHP 배열)로 돌려준다."""

    def __init__(self, fields, files, is_form=False):
        self.fields = fields
        self.files = files
        # 폼(urlencoded, multipart)으로 온 값인지. PHP $_POST 는 폼만 담으므로 CSRF 토큰도 폼에서만 받는다.
        self.is_form = is_form

    def get(self, key, default=None):
        return self.fields.get(key, default)

    def __call__(self, key, default=None):
        return self.fields.get(key, default)

    def str(self, key, default=''):
        """PHP (string) $this->input->post(key) 대응. 배열이 오면 기본값."""
        value = self.fields.get(key)
        return value if isinstance(value, str) else default

    def xss(self, key, default=None):
        """CI3 input->post(key, TRUE) 대응. 값이 없으면 기본값(xss_clean 하지 않음)."""
        if key not in self.fields:
            return default
        return xss_clean(self.fields[key])

    def all(self):
        return self.fields


async def post_data(request: Request) -> PostData:
    """$_POST 대응. FastAPI 의존성으로 쓰며 한 요청 안에서는 한 번만 읽는다.

    폼(urlencoded, multipart)만 읽는다. PHP $_POST 는 JSON 본문을 담지 않으므로 JSON 은 빈 값이다(JSON 은 json_input).
    """
    fields, files = {}, {}

    if request.method in ('GET', 'HEAD', 'OPTIONS'):
        return PostData(fields, files)

    content_type = request.headers.get('content-type', '').lower()

    if content_type.startswith(('application/x-www-form-urlencoded', 'multipart/form-data')):
        # 본문을 먼저 캐시한다. form() 은 캐시가 있으면 그것을 읽으므로, 같은 요청에서 json_input() 이 본문을 다시 읽을 수 있다.
        await request.body()
        form = await request.form()
        for name, value in form.multi_items():
            if isinstance(value, UploadFile):
                _set_php_key(files, name, value)
            else:
                _set_php_key(fields, name, value)
        return PostData(sanitize_globals(fields), files, is_form=True)

    return PostData(fields, files)


def query_data(request: Request) -> PostData:
    """$_GET 대응(CI3 input->get()). 이름 규칙과 시작 때 정리는 $_POST 와 같다."""
    fields = {}
    for name, value in request.query_params.multi_items():
        _set_php_key(fields, name, value)
    return PostData(sanitize_globals(fields), {})


async def json_input(request: Request) -> PostData:
    """PHP json_input() 대응. Content-Type 과 상관없이 본문이 JSON 객체면 그것(정리하지 않은 원문), 아니면 $_POST."""
    body = await request.body()
    try:
        decoded = json.loads(body) if body else None
    except ValueError:
        decoded = None

    if isinstance(decoded, dict):
        return PostData(decoded, {})
    if isinstance(decoded, list):
        # PHP 는 목록도 배열로 받는다. 이름으로 찾으면 없으므로 빈 값과 같다.
        return PostData({str(i): v for i, v in enumerate(decoded)}, {})

    return await post_data(request)
