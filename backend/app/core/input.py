"""
파일 위치: backend/app/core/input.py
역할: 요청 본문을 PHP $_POST 와 같은 모양으로 읽기 (CI3 input->post(), json_input() 대응)

화면 JS(jQuery $.ajax)는 폼 인코딩으로 보내고 배열은 'ids[]', 중첩은 'hours[1][0][start]' 처럼 이름에 괄호를 쓴다.
PHP 는 이를 배열로 풀어 주므로 옮긴 코드가 같은 모양을 받도록 여기서 똑같이 푼다.
"""
import json
import re

from fastapi import Request
from starlette.datastructures import UploadFile

_KEY_RE = re.compile(r'^([^\[\]]+)((?:\[[^\[\]]*\])*)$')


def _set_php_key(target, name, value):
    """'a[b][]' 같은 이름을 PHP 와 같이 중첩 dict 로 넣는다. 빈 괄호는 0, 1, 2 ... 순번 키(PHP 배열)."""
    match = _KEY_RE.match(name)
    if not match:
        target[name] = value
        return

    parts = [match.group(1)] + re.findall(r'\[([^\[\]]*)\]', match.group(2))
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

    def all(self):
        return self.fields


async def post_data(request: Request) -> PostData:
    """요청 본문을 PostData 로. FastAPI 의존성으로 쓰며 한 요청 안에서는 한 번만 읽는다.

    - 폼(urlencoded, multipart): PHP $_POST / $_FILES 와 같은 모양
    - JSON 본문: json_input() 처럼 객체면 그대로 쓴다
    """
    fields, files = {}, {}

    if request.method in ('GET', 'HEAD', 'OPTIONS'):
        return PostData(fields, files)

    content_type = request.headers.get('content-type', '').lower()

    if content_type.startswith('application/json'):
        try:
            decoded = json.loads(await request.body() or b'null')
        except ValueError:
            decoded = None
        if isinstance(decoded, dict):
            fields = decoded
        return PostData(fields, files)

    if content_type.startswith(('application/x-www-form-urlencoded', 'multipart/form-data')):
        form = await request.form()
        for name, value in form.multi_items():
            if isinstance(value, UploadFile):
                _set_php_key(files, name, value)
            else:
                _set_php_key(fields, name, value)
        return PostData(fields, files, is_form=True)

    return PostData(fields, files)
