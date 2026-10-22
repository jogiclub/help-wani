#!/usr/bin/env python3
"""
파일 위치: scripts/compare-portals.py
역할: FastAPI 전환 비교 검증(8단계) — 같은 요청을 PHP 포털(:8099)과 FastAPI 포털(:8098)에 보내 응답을 비교한다

- 화면과 읽기 API 를 방문자 / 조직 관리자 / 플랫폼 운영자로 나눠 요청하고, 상태 코드·본문·내려받기 헤더를 비교한다.
- 매번 바뀌는 값(CSRF, 시각, 토큰, 주소의 호스트·포트)과 PHP/Python 의 JSON 표기 차이('\\/', JS 안 주소 따옴표)는 맞춘 뒤 비교한다.
- 읽기만 하지만 로그인·콘솔 목록·대화 열기·내려받기가 남기는 기록(마지막 로그인/접속 시각, 읽음 처리, 감사 로그)이 있다.
  시작 전에 api 컨테이너에서 그 값을 저장하고 끝나면 되돌린다(--no-restore 로 끔). 개발 환경 전용이다.

  python3 scripts/compare-portals.py            # 전체 비교, 다르면 종료 코드 1
  python3 scripts/compare-portals.py -v         # 다른 곳의 줄 단위 차이까지
  PHP_BASE=... API_BASE=... PASSWORD=... python3 scripts/compare-portals.py
"""
import argparse
import difflib
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

PHP_BASE = os.environ.get('PHP_BASE', 'http://localhost:8099').rstrip('/')
API_BASE = os.environ.get('API_BASE', 'http://localhost:8098').rstrip('/')
API_CONTAINER = os.environ.get('API_CONTAINER', 'remotehelp-api')
# e2e-test.sh 와 같은 개발용 데모 계정(CLAUDE.md 5장). 다른 환경은 환경변수로 바꾼다.
ADMIN_EMAIL = os.environ.get('ADMIN_EMAIL', 'admin@demo.local')
OPERATOR_EMAIL = os.environ.get('OPERATOR_EMAIL', 'operator@demo.local')
PASSWORD = os.environ.get('PASSWORD', 'Test1234!admin')
ORG_CODE = os.environ.get('ORG_CODE', 'demo')

HEADERS_TO_COMPARE = ('content-type', 'content-disposition', 'cache-control')


# ------------------------------------------------------------------ HTTP

class NoRedirect(urllib.request.HTTPRedirectHandler):
    """리다이렉트를 따라가지 않고 그 응답(상태 코드, Location)을 비교한다."""

    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.csrf = ''

    def request(self, path, data=None, ajax=False):
        headers = {'X-Requested-With': 'XMLHttpRequest'} if ajax else {}
        body = None
        if data is not None:
            body = urllib.parse.urlencode(dict(data, csrf_test_name=self.csrf)).encode()
        req = urllib.request.Request(self.base + path, data=body, headers=headers)
        try:
            resp = self.opener.open(req, timeout=30)
            return resp.status, {k.lower(): v for k, v in resp.headers.items()}, resp.read()
        except urllib.error.HTTPError as error:
            return error.code, {k.lower(): v for k, v in error.headers.items()}, error.read()

    def login(self, email):
        _, _, html = self.request('/login')
        match = re.search(rb'name="csrf-hash" content="([^"]+)"', html)
        self.csrf = match.group(1).decode() if match else ''
        status, _, body = self.request('/login', {'email': email, 'password': PASSWORD}, ajax=True)
        if status != 200 or b'"result":true' not in body:
            raise SystemExit(f'{self.base} 에 {email} 로 로그인하지 못했습니다({status}): {body[:200]!r}')
        # 로그인하면 세션이 새로 만들어지므로 CSRF 값을 다시 읽는다.
        _, _, html = self.request('/console')
        match = re.search(rb'name="csrf-hash" content="([^"]+)"', html)
        self.csrf = match.group(1).decode() if match else self.csrf


# ------------------------------------------------------------------ 정규화

def normalize(raw):
    text = raw.decode('utf-8', 'replace')
    text = re.sub(r'csrf-hash" content="[^"]+"', 'csrf-hash" content="X"', text)
    text = re.sub(r'name="csrf_test_name" value="[^"]+"', 'name="csrf_test_name" value="X"', text)
    for base in (PHP_BASE, API_BASE):
        host = base.split('://', 1)[1]
        text = text.replace(host, 'HOST').replace(host.replace('/', '\\/'), 'HOST')
    # PHP json_encode 는 '/' 를 '\/' 로, PHP 뷰는 JS 주소를 작은따옴표로 쓴다(FastAPI 는 |js 로 큰따옴표). 같은 값이다.
    text = text.replace('\\/', '/')
    text = re.sub(r"'((?:https?:)?//HOST[^']*)'", r'"\1"', text)
    text = text.replace('&#039;', '&#39;').replace('&#34;', '&quot;')
    text = re.sub(r'"(token|viewer_token|server_time)":"[^"]*"', r'"\1":"X"', text)
    text = re.sub(r'"remain_sec":\d+', '"remain_sec":0', text)
    text = re.sub(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z', 'ISO', text)
    text = re.sub(r'-\d{8}\.(csv|json)', r'-D.\1', text)
    text = re.sub(r'\s+', ' ', text)
    text = re.sub(r'\s*(<|>)\s*', r'\1', text)
    return [part for part in re.split(r'(?=<)|(?<=,)(?=")', text) if part.strip()]


# ------------------------------------------------------------------ 기록 저장·복원 (api 컨테이너 안에서 실행)

_STATE_CODE = r'''
import json, sys
from app import db
mode, emails, conv = sys.argv[1], json.loads(sys.argv[2]), int(sys.argv[3])
if mode == 'save':
    s = {'agents': [dict(r) for r in db.query('SELECT * FROM agents WHERE email IN :e', {'e': emails})],
         'audit_max': int(db.scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs')),
         'conv': [dict(r) for r in db.query('SELECT id, unread_for_agent FROM conversations WHERE id = :c', {'c': conv})],
         'read': [dict(r) for r in db.query('SELECT id, read_at FROM messages WHERE conversation_id = :c', {'c': conv})]}
    print(json.dumps(s))
else:
    s = json.loads(sys.stdin.read())
    with db.transaction():
        for a in s['agents']:
            db.update('agents', {k: v for k, v in a.items() if k != 'id'}, {'id': int(a['id'])})
        for c in s['conv']:
            db.update('conversations', {'unread_for_agent': c['unread_for_agent']}, {'id': int(c['id'])})
        for m in s['read']:
            db.update('messages', {'read_at': m['read_at']}, {'id': int(m['id'])})
        db.execute('DELETE FROM audit_logs WHERE id > :m', {'m': s['audit_max']})
    db.execute('ALTER TABLE audit_logs AUTO_INCREMENT = %d' % (int(db.scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs')) + 1))
    print('ok')
'''

_IDS_CODE = r'''
import json, sys
from app import db
org = db.row('SELECT id FROM organizations WHERE org_code = :c', {'c': sys.argv[1]})
o = int(org.id)
one = lambda sql: (db.row(sql, {'o': o}) or {}).get('id')
print(json.dumps({
    'session': one('SELECT id FROM sessions WHERE org_id = :o ORDER BY id DESC LIMIT 1'),
    'conversation': one("SELECT c.id FROM conversations c WHERE c.org_id = :o AND EXISTS (SELECT 1 FROM messages m "
                        "WHERE m.conversation_id = c.id AND m.sender_type = 'visitor') ORDER BY c.id DESC LIMIT 1"),
    'article': one('SELECT id FROM guide_articles WHERE org_id = :o ORDER BY id LIMIT 1'),
    'published': one("SELECT id FROM guide_articles WHERE org_id = :o AND status = 'published' ORDER BY id LIMIT 1"),
    'survey_session': one("SELECT id FROM sessions WHERE org_id = :o AND status = 'ended' ORDER BY id DESC LIMIT 1"),
}))
'''


def container_python(code, *args, stdin=None):
    result = subprocess.run(['docker', 'exec', '-i', '-w', '/app', '-e', 'PYTHONPATH=/app', API_CONTAINER, 'python', '-c', code, *args],
                            input=stdin, capture_output=True, text=True)
    if result.returncode != 0:
        raise SystemExit(f'api 컨테이너({API_CONTAINER})에서 실행하지 못했습니다:\n{result.stderr[-800:]}')
    return result.stdout.strip()


# ------------------------------------------------------------------ 비교 항목

def build_steps(ids):
    """(계정, 경로, POST 값 또는 None, AJAX 여부, 헤더 비교 여부). 계정 None 은 로그인하지 않은 방문자."""
    sid, cid, aid = ids['session'] or 0, ids['conversation'] or 0, ids['article'] or 0
    pub, survey = ids['published'] or 0, ids['survey_session'] or 0
    q = urllib.parse.quote('프린터 연결이 안 돼요')
    org = ORG_CODE

    visitor = ['/', '/home', '/login', '/signup', f'/{org}', f'/{org}/guide', f'/{org}/guide?q={q}', f'/{org}/guide/{pub}',
               f'/{org}/guide/features', f'/{org}/guide/videos', f'/{org}/guide/999999', f'/{org}/download', f'/{org}/survey/{survey}',
               f'/{org}/chat/bad-token', '/no-such-org', '/console', '/admin', '/operator', '/widget.js', '/api/launcher/version',
               '/lang/en', '/console/api/chat/list']
    steps = [(None, p, None, p.startswith('/console/api'), False) for p in visitor]

    admin = [
        ('/console', False), ('/console/history', False), (f'/console/session/{sid}', False), ('/console/session/999999', False),
        ('/console/api/session/list', True), ('/console/api/session/list?status=ended&keyword=a', True),
        (f'/console/api/session/detail/{sid}', True), ('/console/chat', False), ('/console/chat?settings=widget', False),
        ('/console/api/chat/list', True), ('/console/api/chat/list?status=closed', True),
        (f'/console/api/chat/messages/{cid}', True), (f'/console/api/guide/suggest/{cid}', True),
        (f'/console/api/guide/search?q={q}', True), ('/console/macro', False), ('/console/api/macro/list', True),
        (f'/console/api/macro/suggest/{cid}', True),
        ('/admin', False), ('/admin/agents', False), ('/admin/organization', False), ('/admin/widget', False),
        ('/admin/guide', False), ('/admin/guide/new', False), (f'/admin/guide/edit/{aid}', False), (f'/admin/guide/preview/{aid}', False),
        ('/admin/guide/edit/999999', False), (f'/admin/guide/api/match_test?q={q}', True), ('/admin/api/holiday_candidates', True),
        ('/admin/api/nope', True), ('/operator', False), ('/operator/api/org_approve', True),
    ]
    steps += [(ADMIN_EMAIL, p, None, ajax, False) for p, ajax in admin]
    # 내려받기는 헤더(파일 이름, 형식)도 비교한다.
    steps += [(ADMIN_EMAIL, p, None, False, True) for p in
              ('/console/macro/template', '/console/macro/export?scope=personal', '/admin/guide/export')]

    operator = [('/operator', False), ('/operator?status=pending', False), ('/operator?status=active', False),
                ('/operator?status=nope', False), ('/operator/audit', False), ('/operator/api/nope', True), ('/admin', False)]
    steps += [(OPERATOR_EMAIL, p, None, ajax, False) for p, ajax in operator]
    return steps


# ------------------------------------------------------------------ 실행

def main():
    parser = argparse.ArgumentParser(description='PHP 포털과 FastAPI 포털의 응답 비교')
    parser.add_argument('-v', '--verbose', action='store_true', help='다른 곳의 줄 단위 차이를 보여 준다')
    parser.add_argument('--no-restore', action='store_true', help='로그인·읽음 처리 기록을 되돌리지 않는다')
    args = parser.parse_args()

    ids = json.loads(container_python(_IDS_CODE, ORG_CODE))
    emails = json.dumps([ADMIN_EMAIL, OPERATOR_EMAIL])
    saved = None if args.no_restore else container_python(_STATE_CODE, 'save', emails, str(ids['conversation'] or 0))

    clients = {None: (Client(PHP_BASE), Client(API_BASE))}
    bad = 0
    try:
        for email in (ADMIN_EMAIL, OPERATOR_EMAIL):
            pair = (Client(PHP_BASE), Client(API_BASE))
            for client in pair:
                client.login(email)
            clients[email] = pair

        steps = build_steps(ids)
        for email, path, data, ajax, compare_headers in steps:
            php, api = clients[email]
            a = php.request(path, data, ajax)
            b = api.request(path, data, ajax)
            body_a, body_b = normalize(a[2]), normalize(b[2])
            head_a = {k: a[1].get(k) for k in HEADERS_TO_COMPARE} if compare_headers else {}
            head_b = {k: b[1].get(k) for k in HEADERS_TO_COMPARE} if compare_headers else {}
            loc_a = normalize(a[1].get('location', '').encode())
            loc_b = normalize(b[1].get('location', '').encode())
            same = a[0] == b[0] and body_a == body_b and head_a == head_b and loc_a == loc_b
            bad += not same
            who = email.split('@')[0] if email else '방문자'
            print(f"{'같음' if same else '다름'}  {a[0]} {b[0]}  {who:9s} {path}")
            if not same and args.verbose:
                if head_a != head_b:
                    print('      헤더 PHP', head_a, '\n      헤더 API', head_b)
                if loc_a != loc_b:
                    print('      Location', loc_a, loc_b)
                for line in list(difflib.unified_diff(body_a, body_b, lineterm='', n=0))[2:14]:
                    print('      ' + line[:240])
        print(f'\n비교 {len(steps)}건, 다름 {bad}건')
    finally:
        if saved is not None:
            container_python(_STATE_CODE, 'restore', emails, '0', stdin=saved)
            print('로그인·접속 시각, 읽음 처리, 감사 로그를 비교 전 값으로 되돌렸습니다.')

    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
