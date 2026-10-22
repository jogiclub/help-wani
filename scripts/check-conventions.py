#!/usr/bin/env python3
"""
파일 위치: scripts/check-conventions.py
역할: CLAUDE.md 3장의 규칙이 지켜졌는지 자동 점검한다.

조용히 깨지는 것들(사전 키 누락, 아이콘 목록 누락, CSS 미빌드 등)을 잡는 것이 목적이다.
새 규칙을 추가하면 여기에 검사도 함께 넣는다.

사용법:
    python3 scripts/check-conventions.py          # 정적 검사
    python3 scripts/check-conventions.py --live   # 실행 중인 개발 서버까지 점검
"""
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

errors = []
warnings = []
passed = []


def ok(message):
    passed.append(message)


def fail(message, detail=None):
    errors.append((message, detail))


def warn(message, detail=None):
    warnings.append((message, detail))


def read(path):
    with open(os.path.join(ROOT, path), encoding='utf-8') as f:
        return f.read()


def walk(rel_dir, suffixes, skip=()):
    base = os.path.join(ROOT, rel_dir)

    for dirpath, dirnames, filenames in os.walk(base):
        dirnames[:] = [d for d in dirnames if not any(s in os.path.join(dirpath, d) for s in skip)]

        for name in filenames:
            if name.endswith(suffixes):
                full = os.path.join(dirpath, name)
                yield os.path.relpath(full, ROOT), full


# ---------------------------------------------------------------- 3.1 Tailwind
def check_tailwind():
    css_path = os.path.join(ROOT, 'portal/assets/css/app.css')

    if not os.path.isfile(css_path):
        fail('Tailwind 결과물이 없습니다', 'portal/assets/css/app.css — ./scripts/build-css.sh 실행')
        return

    cli = os.path.join(ROOT, '.tools/tailwindcss')

    if os.access(cli, os.X_OK):
        # CLI 가 있으면 다시 빌드해 내용이 같은지 정확히 비교한다.
        tmp = os.path.join(ROOT, '.tools/_check.css')
        result = subprocess.run(
            [cli, '-i', os.path.join(ROOT, 'portal/assets/css/tailwind.src.css'), '-o', tmp, '--minify'],
            capture_output=True, text=True)

        if result.returncode != 0:
            fail('Tailwind 빌드 실패', result.stderr.strip().splitlines()[-1] if result.stderr else '')
            return

        same = open(tmp, encoding='utf-8').read() == open(css_path, encoding='utf-8').read()
        os.remove(tmp)

        if same:
            ok('Tailwind CSS 가 최신입니다')
        else:
            fail('Tailwind CSS 가 소스와 다릅니다', './scripts/build-css.sh 를 실행해 다시 만드세요')
        return

    # CLI 가 없으면 수정 시각으로 어림 판단한다.
    newest = 0
    for rel, full in list(walk('portal/application/views', ('.php',))) + \
                     list(walk('portal/assets/js', ('.js',))):
        newest = max(newest, os.path.getmtime(full))

    newest = max(newest, os.path.getmtime(os.path.join(ROOT, 'portal/assets/css/tailwind.src.css')))

    if os.path.getmtime(css_path) < newest:
        warn('Tailwind CSS 가 오래되었을 수 있습니다', './scripts/build-css.sh 로 확인하세요')
    else:
        ok('Tailwind CSS 수정 시각 확인')


# ------------------------------------------------------------------ 3.2 다국어
def check_locales():
    lang_dir = 'portal/assets/lang'
    files = sorted(f for f in os.listdir(os.path.join(ROOT, lang_dir)) if f.endswith('.json'))

    if not files:
        fail('언어 사전이 없습니다', lang_dir)
        return

    keys = {}
    for name in files:
        data = json.loads(read(os.path.join(lang_dir, name)))
        keys[name[:-5]] = set(data)

    base = 'ko'
    problems = []

    for locale, key_set in keys.items():
        if locale == base:
            continue
        missing = sorted(keys[base] - key_set)
        extra = sorted(key_set - keys[base])

        if missing:
            problems.append(f'{locale}.json 에 없는 키 {len(missing)}개: ' + ', '.join(missing[:5]) +
                            (' ...' if len(missing) > 5 else ''))
        if extra:
            problems.append(f'{locale}.json 에만 있는 키 {len(extra)}개: ' + ', '.join(extra[:5]) +
                            (' ...' if len(extra) > 5 else ''))

    if problems:
        fail('언어 사전의 키가 서로 다릅니다', '\n    '.join(problems))
    else:
        ok(f'언어 사전 {len(keys)}개 키 일치 ({len(keys[base])}개)')


# ------------------------------------------------------------------ 3.3 아이콘
def declared_icons():
    source = read('portal/application/controllers/Home.php')
    block = re.search(r'function landing_icons\(\).*?\n    \}', source, re.S)

    if not block:
        return None

    return set(re.findall(r"=>\s*'([a-z0-9_]+)'", block.group(0)))


def check_icons():
    declared = declared_icons()

    if declared is None:
        fail('Home::landing_icons() 를 찾지 못했습니다')
        return

    # 뷰나 JS 에 아이콘 이름을 직접 적은 경우를 찾는다.
    literals = set()
    for rel, full in list(walk('portal/application/views', ('.php',))) + \
                     list(walk('portal/assets/js', ('.js',))):
        literals |= set(re.findall(r'material-symbols-outlined[^>]*>\s*([a-z][a-z0-9_]+)\s*<',
                                   open(full, encoding='utf-8').read()))

    missing = sorted(literals - declared)

    if missing:
        fail('아이콘이 Home::landing_icons() 목록에 없습니다',
             ', '.join(missing) + ' — 목록에 없으면 아이콘 대신 글자가 보입니다')
    else:
        ok(f'아이콘 목록 동기화 ({len(declared)}개 선언)')


# -------------------------------------------------------------------- 3.4 시간
def check_utc():
    compose = read('docker-compose.yml')
    php_ini = read('docker/web/php.ini')

    if '--default-time-zone=+00:00' not in compose:
        fail('MySQL 이 UTC 로 설정되지 않았습니다', 'docker-compose.yml 의 --default-time-zone=+00:00')
    elif not re.search(r'^\s*date\.timezone\s*=\s*UTC', php_ini, re.M):
        fail('PHP 가 UTC 로 설정되지 않았습니다', 'docker/web/php.ini 의 date.timezone = UTC')
    else:
        ok('시간 저장 기준이 UTC 입니다')


# ------------------------------------------------------------------ 3.5 wgrid
WGRID_DIR = 'portal/assets/vendor/wgrid/0.1.0'


def check_grid():
    # AG Grid 옵션 이름이 남아 있으면 wgrid 가 조용히 무시해 열이 비거나 이벤트가 안 걸린다.
    leftovers = ('agGrid', 'columnDefs', 'headerName', 'valueFormatter', 'cellRenderer',
                 'setGridOption', 'applyTransaction', 'getRowId', 'overlayNoRowsTemplate',
                 # wgrid Pro 는 운영 도메인에서 라이선스 키가 필요하다.
                 'wgridPro', 'pro.global.js')
    found = []

    for rel, full in walk('portal/assets/js', ('.js',)):
        body = open(full, encoding='utf-8').read()
        for name in leftovers:
            if name in body:
                found.append(f'{rel}: {name}')

    if found:
        fail('AG Grid 옵션이나 wgrid Pro 를 쓰고 있습니다', '\n    '.join(found))
        return

    missing = [name for name in ('wgrid.global.js', 'wgrid.css')
               if not os.path.isfile(os.path.join(ROOT, WGRID_DIR, name))]

    if missing:
        fail('wgrid 파일이 없습니다', f'{WGRID_DIR}/' + ', '.join(missing))
        return

    grid_js = read('portal/assets/js/grid.js')
    size = re.search(r'DEFAULT_PAGE_SIZE\s*=\s*(\d+)', grid_js)

    if not size or size.group(1) != '20':
        fail('그리드 기본 페이지 크기가 20 이 아닙니다', 'portal/assets/js/grid.js')
    else:
        ok('wgrid 설정 확인 (코어만, 기본 20개)')


# ------------------------------------------------------------- 3.7 파일 상단 주석
def check_headers():
    targets = (
        ('portal/application/controllers', ('.php',), ()),
        ('portal/application/models', ('.php',), ()),
        ('portal/application/helpers', ('.php',), ()),
        ('portal/application/core', ('.php',), ()),
        ('portal/assets/js', ('.js',), ('vendor',)),
        ('relay/src', ('.js',), ()),
        ('realtime/src', ('.js',), ()),
        ('launcher/RemoteHelp.App', ('.cs',), ('bin', 'obj')),
        ('backend/app', ('.py',), ('__pycache__',)),
    )

    missing = []

    for rel_dir, suffixes, skip in targets:
        if not os.path.isdir(os.path.join(ROOT, rel_dir)):
            continue

        for rel, full in walk(rel_dir, suffixes, skip):
            head = open(full, encoding='utf-8').read(600)

            if '파일 위치:' not in head or '역할:' not in head:
                missing.append(rel)

    if missing:
        fail(f'파일 상단 주석(파일 위치/역할)이 없습니다 — {len(missing)}개',
             '\n    '.join(missing[:10]) + ('\n    ...' if len(missing) > 10 else ''))
    else:
        ok('파일 상단 주석 확인')


# ------------------------------------------------------------- 3.9 상담 위젯
def check_widget():
    """위젯은 고객사 사이트 안에서 도는 코드라 포털 자산을 쓰면 안 된다."""
    widget_js = os.path.join(ROOT, 'portal/assets/js/widget.js')
    routes = read('portal/application/config/routes.php')
    problems = []

    if os.path.isfile(widget_js):
        raw = open(widget_js, encoding='utf-8').read()

        # 규칙을 설명하는 주석에 금지어가 나오므로 주석을 걷어내고 본다.
        body = re.sub(r'//[^\n]*', '', re.sub(r'/\*.*?\*/', '', raw, flags=re.S))

        forbidden = (
            ('app.css', '포털 Tailwind CSS'),
            ('common.js', '포털 공통 스크립트'),
            ('i18n.js', '포털 번역 스크립트'),
            ('RH_I18N', '포털 번역 전역 변수'),
            ('jQuery', 'jQuery'),
        )

        for needle, label in forbidden:
            if needle in body:
                problems.append(f'widget.js 가 {label}({needle}) 에 기대고 있습니다')

        if re.search(r'(?<![\w$])\$\(', body):
            problems.append('widget.js 에서 jQuery 형태의 $() 를 쓰고 있습니다')

        if 'attachShadow' not in raw:
            problems.append('widget.js 가 Shadow DOM(attachShadow) 을 쓰지 않습니다 — 고객사 CSS 와 충돌합니다')

    # 위젯 API 를 열었으면 CSRF 제외 목록에 넣어야 외부 도메인에서 호출된다.
    if 'api/widget' in routes:
        config = read('portal/application/config/config.php')
        line = re.search(r"\$config\['csrf_exclude_uris'\]\s*=\s*array\((.*?)\);", config, re.S)

        if not line or 'api/widget' not in line.group(1):
            problems.append("csrf_exclude_uris 에 'api/widget/.*' 가 없습니다 — 외부 도메인 호출이 막힙니다")

    if problems:
        fail('상담 위젯 규칙 위반 (CLAUDE.md 3.9)', '\n    '.join(problems))
    elif os.path.isfile(widget_js):
        ok('상담 위젯 규칙 확인 (독립 실행, Shadow DOM, CSRF 제외)')
    else:
        ok('상담 위젯 미구현 — 검사 건너뜀')


# ------------------------------------------------- 3.7 API 주소는 절대 경로로
def check_api_urls():
    """JS 가 API 주소를 상대 경로로 적으면 화면 깊이에 따라 404 가 난다."""
    pattern = re.compile(r"""api(?:Get|Post)\(\s*['"](?!https?://|/)""")
    found = []

    for rel, full in walk('portal/assets/js', ('.js',), ('vendor',)):
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if pattern.search(line):
                found.append(f'{rel}:{number}: {line.strip()[:70]}')

    if found:
        fail('JS 에서 API 주소를 상대 경로로 적었습니다 (CLAUDE.md 3.7)',
             '\n    '.join(found[:10]) + '\n    뷰에서 base_url() 로 만든 주소를 넘기세요')
    else:
        ok('JS API 주소가 모두 절대 경로입니다')


# ------------------------------------------------------------------ 3.8 라우팅
def check_routes():
    """조직 코드를 받는 $route['(:any)'] 는 맨 아래여야 한다. 그 아래 규칙은 영영 닿지 않는다."""
    lines = read('portal/application/config/routes.php').splitlines()
    any_rule_at = None

    for index, line in enumerate(lines):
        if not line.strip().startswith('$route['):
            continue

        # '(:any)/guide/(:any)' 처럼 (:any) 로 끝나는 다른 규칙과 헷갈리지 않도록 키 전체를 비교한다.
        if re.match(r"\s*\$route\['\(:any\)'\]\s*=", line):
            any_rule_at = index
        elif any_rule_at is not None:
            fail('조직 코드 규칙 $route[\'(:any)\'] 아래에 다른 라우트가 있습니다',
                 f'{index + 1}행: {line.strip()} — (:any) 규칙은 맨 아래여야 합니다')
            return

    if any_rule_at is None:
        fail("조직 코드 규칙 $route['(:any)'] 가 없습니다", '고객 접속 페이지(/{조직코드})가 열리지 않습니다')
        return

    ok('라우팅 순서 확인')


# ------------------------------------------------------------------ 3.10 용어
def check_terms():
    """원격(sessions)과 상담(conversations)을 가리키는 말이 섞였는지 본다."""
    banned = {
        '상담 대기열': '원격 대기열',
        '상담 이력': '원격 이력',
        '상담 건수': '원격지원 건수',
        '평균 상담 시간': '평균 원격 시간',
        '상담 메모': '원격 메모',
        '원격 대화': '상담 대화',
        '원격 위젯': '상담 위젯',
    }

    found = []

    for rel_dir, suffixes in (('portal/application/views', ('.php',)),
                              ('portal/application/controllers', ('.php',)),
                              ('portal/assets/js', ('.js',))):
        for rel, full in walk(rel_dir, suffixes, ('vendor',)):
            for number, line in enumerate(open(full, encoding='utf-8'), 1):
                for wrong, right in banned.items():
                    if wrong in line:
                        found.append(f'{rel}:{number} "{wrong}" -> "{right}"')

    if found:
        fail('원격과 상담 용어가 섞였습니다 (CLAUDE.md 3.10)', '\n    '.join(found[:10]))
    else:
        ok('원격/상담 용어 구분 확인')


# ------------------------------------- 3.11 컨트롤러와 라이브러리 이름 충돌
def check_class_collision():
    """CI3 는 컨트롤러와 같은 이름의 라이브러리를 읽지 못한다(503 으로 끝난다)."""
    def class_names(rel_dir):
        names = {}
        for rel, full in walk(rel_dir, ('.php',)):
            for match in re.finditer(r'^\s*class\s+([A-Za-z_][A-Za-z0-9_]*)', 
                                     open(full, encoding='utf-8').read(), re.M):
                names[match.group(1).lower()] = rel
        return names

    controllers = class_names('portal/application/controllers')
    libraries = class_names('portal/application/libraries')

    clashes = [f'{name}: {controllers[name]} / {libraries[name]}'
               for name in sorted(set(controllers) & set(libraries))]

    if clashes:
        fail('컨트롤러와 라이브러리의 클래스 이름이 겹칩니다 (CLAUDE.md 3.11)',
             '\n    '.join(clashes) + '\n    한쪽 이름을 바꾸세요 (예: Realtime -> Realtime_auth)')
    else:
        ok('컨트롤러/라이브러리 이름 충돌 없음')


# -------------------------------------------------------------------- 비밀값
def check_secrets():
    tracked = subprocess.run(['git', 'ls-files', '.env'], cwd=ROOT,
                             capture_output=True, text=True).stdout.strip()

    if tracked:
        fail('.env 가 git 에 추적되고 있습니다', '비밀값이 저장소에 들어갑니다')
        return

    env_path = os.path.join(ROOT, '.env')

    if not os.path.isfile(env_path):
        ok('.env 미추적 확인')
        return

    # .env.example 에 그대로 있는 값은 자리표시자이므로 검사에서 뺀다.
    example_path = os.path.join(ROOT, '.env.example')
    placeholders = set()

    if os.path.isfile(example_path):
        for line in open(example_path, encoding='utf-8'):
            if '=' in line and not line.strip().startswith('#'):
                placeholders.add(line.strip().partition('=')[2].strip())

    leaked = []

    for line in open(env_path, encoding='utf-8'):
        if '=' not in line or line.strip().startswith('#'):
            continue

        name, _, value = line.strip().partition('=')
        value = value.strip()

        # 길고 고유한 값만 검사한다. 짧은 값이나 자리표시자는 우연히 일치할 수 있다.
        if len(value) < 16 or value.startswith(('http', 'wss', 'ws')) or value in placeholders:
            continue

        hit = subprocess.run(['git', 'grep', '-l', '--', value], cwd=ROOT,
                             capture_output=True, text=True)

        if hit.stdout.strip():
            leaked.append(f'{name} → {hit.stdout.strip().splitlines()[0]}')

    if leaked:
        fail('.env 의 비밀값이 추적되는 파일에 들어 있습니다', '\n    '.join(leaked))
    else:
        ok('비밀값 노출 없음')


# -------------------------------------------------------------- VNC 잔재 확인
def check_no_vnc():
    found = []
    pattern = re.compile(r'\b(uvncrepeater|websockify|novnc|winvnc|repeater_id|vnc_password)\b', re.I)

    for rel_dir, suffixes in (('portal/application', ('.php',)),
                              ('portal/assets/js', ('.js',)),
                              ('relay/src', ('.js',)),
                              ('launcher/RemoteHelp.App', ('.cs',))):
        if not os.path.isdir(os.path.join(ROOT, rel_dir)):
            continue

        for rel, full in walk(rel_dir, suffixes, ('bin', 'obj', 'vendor')):
            for number, line in enumerate(open(full, encoding='utf-8'), 1):
                if pattern.search(line):
                    found.append(f'{rel}:{number}')

    if found:
        fail('VNC 시절 코드가 남아 있습니다', '\n    '.join(found[:10]))
    else:
        ok('VNC 잔재 없음')


# --------------------------------------------------- 터널 프록시 신뢰 주소 일치
def check_tunnel_proxy():
    compose = read('docker-compose.yml')
    vhost = read('docker/web/apache-vhost.conf')

    if 'RemoteIPHeader' not in vhost:
        ok('터널 프록시 미사용')
        return

    trusted = re.findall(r'^\s*RemoteIP(?:Internal|Trusted)Proxy\s+(.+)$', vhost, re.M)
    trusted = [t for line in trusted for t in line.split()]
    match = re.search(r'cloudflared:.*?ipv4_address:\s*([0-9.]+)', compose, re.S)

    if not match:
        fail('cloudflared 고정 주소가 없습니다',
             'docker-compose.yml 의 cloudflared 에 ipv4_address 를 두고 apache-vhost.conf 와 맞춥니다')
    elif any('/' in t for t in trusted):
        fail('RemoteIP 신뢰 주소가 대역으로 되어 있습니다',
             '8099 직접 요청이 tunnel 망 게이트웨이로 들어와 CF-Connecting-IP 를 위조할 수 있습니다. cloudflared 주소 하나만 둡니다')
    elif trusted != [match.group(1)]:
        fail('RemoteIP 신뢰 주소와 cloudflared 주소가 다릅니다',
             f'apache-vhost.conf {trusted} / docker-compose.yml {match.group(1)}')
    else:
        ok('터널 프록시 신뢰 주소 일치')


# ------------------------------------------- FastAPI 전환 (docs/fastapi-migration-plan.md)
def check_fastapi():
    if not os.path.isdir(os.path.join(ROOT, 'backend/app')):
        ok('FastAPI 포털 없음')
        return

    compose = read('docker-compose.yml')
    dockerfile = read('docker/api/Dockerfile')
    db_py = read('backend/app/db.py')
    problems = []

    # 3.12: 고객 IP 는 X-Forwarded-For 로 받지 않는다. uvicorn 은 기본으로 127.0.0.1 의 프록시 헤더를 믿는다.
    api_block = re.search(r'^  api:\n(.*?)(?=^  \S)', compose, re.S | re.M)
    api_block = api_block.group(1) if api_block else ''
    for name, text in (('docker/api/Dockerfile', dockerfile), ('docker-compose.yml api', api_block)):
        if 'uvicorn' in text and '--no-proxy-headers' not in text:
            problems.append(f'{name}: uvicorn 에 --no-proxy-headers 가 없습니다(X-Forwarded-For 로 고객 IP 를 위조할 수 있음)')

    # PHP 와 FastAPI 가 같은 환경변수를 봐야 서명 토큰이 어긋나지 않는다.
    web_block = re.search(r'^  web:\n(.*?)(?=^  \S)', compose, re.S | re.M)
    web_block = web_block.group(1) if web_block else ''
    if 'x-portal-env: &portal-env' not in compose:
        problems.append('docker-compose.yml: 포털 환경변수 묶음(x-portal-env)이 없습니다')
    if 'environment: *portal-env' not in web_block:
        problems.append('docker-compose.yml web: 환경변수를 *portal-env 로 받지 않습니다(api 와 값이 어긋날 수 있음)')
    if '<<: *portal-env' not in api_block:
        problems.append('docker-compose.yml api: 환경변수를 *portal-env 로 받지 않습니다(web 과 값이 어긋날 수 있음)')

    # 3.4 와 PHP(CI3 stricton = FALSE) 와 같은 DB 세션 설정
    if "time_zone = '+00:00'" not in db_py:
        problems.append("backend/app/db.py: DB 세션 시간대를 '+00:00' 으로 두지 않습니다")
    if db_py.count('STRICT_TRANS_TABLES') < 3 or db_py.count('STRICT_ALL_TABLES') < 3:
        problems.append('backend/app/db.py: PHP 처럼 sql_mode 에서 STRICT_* 를 빼지 않습니다')

    # 버전을 고정하지 않으면 다시 빌드할 때 다른 버전이 들어온다.
    loose = []
    for line in read('backend/requirements.txt').splitlines():
        line = line.split('#', 1)[0].strip()
        if line and '==' not in line:
            loose.append(line)
    if loose:
        problems.append('backend/requirements.txt: 버전이 고정되지 않았습니다 — ' + ', '.join(loose))

    # 3.12: 고객 IP 를 믿는 프록시 주소는 cloudflared 고정 주소 하나여야 한다.
    request_py = os.path.join(ROOT, 'backend/app/core/request.py')
    if os.path.isfile(request_py):
        trusted = re.search(r"^TRUSTED_PROXY_IP = '([0-9.]+)'", read('backend/app/core/request.py'), re.M)
        cloudflared = re.search(r'cloudflared:.*?ipv4_address:\s*([0-9.]+)', compose, re.S)
        if not trusted or not cloudflared or trusted.group(1) != cloudflared.group(1):
            problems.append('backend/app/core/request.py: TRUSTED_PROXY_IP 가 docker-compose.yml 의 cloudflared 주소와 다릅니다')

    # 같은 호스트의 다른 포트는 쿠키를 함께 쓴다. PHP 쿠키 이름(ci_session, csrf_cookie_name)을 쓰면 서로 덮어쓴다.
    for rel in ('backend/app/core/session.py', 'backend/app/core/csrf.py'):
        if os.path.isfile(os.path.join(ROOT, rel)):
            name = re.search(r"^COOKIE_NAME = '([^']+)'", read(rel), re.M)
            if not name or name.group(1) in ('ci_session', 'csrf_cookie_name'):
                problems.append(f'{rel}: 쿠키 이름이 없거나 PHP 와 같습니다(PHP 세션·CSRF 쿠키를 덮어씀)')

    if problems:
        fail('FastAPI 전환 규칙 위반', '\n    '.join(problems))
    else:
        ok('FastAPI 포털 설정 확인 (프록시 헤더 끔, PHP 와 같은 환경변수, UTC, sql_mode, 버전 고정, 신뢰 프록시, 쿠키 이름)')


def _py_literal(rel, function=None, name=None):
    """backend 파이썬 파일에서 함수가 돌려주는 값(function) 또는 모듈 상수(name)를 읽는다(의존성 없이 ast 로)."""
    import ast
    tree = ast.parse(read(rel))
    for node in tree.body:
        if function and isinstance(node, ast.FunctionDef) and node.name == function:
            for sub in ast.walk(node):
                if isinstance(sub, ast.Return) and sub.value is not None:
                    return ast.literal_eval(sub.value)
        if name and isinstance(node, ast.Assign) and any(getattr(t, 'id', None) == name for t in node.targets):
            return ast.literal_eval(node.value)
    return None


def _php_config_array(text, key):
    match = re.search(r"\$config\['" + key + r"'\] = array\((.*?)\);", text, re.S)
    return re.findall(r"'([^']*)'", re.sub(r'//[^\n]*', '', match.group(1))) if match else None


# PHP 설정 배열을 PHP 없이 읽는다(설정 대조용). 작은따옴표/큰따옴표 문자열, '.' 이어 붙이기, array(키 => 값), 숫자만 다룬다.
_TOKEN = re.compile(r"""\s+|//[^\n]*|/\*.*?\*/|'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"|=>|array\(|[(),.\]\[]|-?\d+(?:\.\d+)?|TRUE|FALSE|NULL|true|false|null""", re.S)
_DQ = {'n': '\n', 't': '\t', 'r': '\r', 'v': '\v', 'f': '\f', '\\': '\\', '$': '$', '"': '"', '0': '\0', 'e': '\x1b'}


def _php_tokens(text):
    pos, out = 0, []
    while pos < len(text):
        m = _TOKEN.match(text, pos)
        if not m:
            raise ValueError('PHP 배열 해석 실패: ' + text[pos:pos + 40])
        tok = m.group(0)
        pos = m.end()
        if tok.strip() == '' or tok.startswith(('//', '/*')):
            continue
        out.append(tok)
    return out


def _php_string(tok):
    body = tok[1:-1]
    if tok[0] == "'":
        return re.sub(r"\\([\\'])", r'\1', body)
    return re.sub(r'\\(.)', lambda m: _DQ.get(m.group(1), '\\' + m.group(1)), body)


def _php_value(text):
    """PHP 설정의 값(array(...), 문자열 . 이어 붙이기, 숫자)을 파이썬 값으로. 키가 있으면 dict, 없으면 list."""
    toks = _php_tokens(text)
    pos = 0

    def scalar():
        nonlocal pos
        tok = toks[pos]
        pos += 1
        if tok == 'array(':
            items, keyed = [], False
            while toks[pos] != ')':
                value = scalar()
                if toks[pos] == '=>':
                    pos += 1
                    items.append((value, scalar()))
                    keyed = True
                else:
                    items.append((None, value))
                if toks[pos] == ',':
                    pos += 1
            pos += 1
            return {k: v for k, v in items} if keyed else [v for _, v in items]
        if tok[0] in '\'"':
            value = _php_string(tok)
            while pos < len(toks) and toks[pos] == '.':
                pos += 1
                value += _php_string(toks[pos])
                pos += 1
            return value
        if tok.upper() in ('TRUE', 'FALSE', 'NULL'):
            return {'TRUE': True, 'FALSE': False, 'NULL': None}[tok.upper()]
        return float(tok) if '.' in tok else int(tok)

    return scalar()


def _php_config(text, key):
    m = re.search(r"\$config\['" + key + r"'\]\s*=\s*(array\(.*?\));\s*(?=\$config|\Z|\n\s*\n)", text, re.S)
    return _php_value(m.group(1)) if m else None


def check_fastapi_screens():
    """FastAPI 로 옮긴 화면이 PHP 와 같은 규칙을 지키고, 두 서버가 같아야 하는 목록이 어긋나지 않는지 본다(CLAUDE.md 3.3, 3.8, 3.15, 3.19)."""
    if not os.path.isdir(os.path.join(ROOT, 'backend/app/templates/guide')):
        ok('FastAPI 화면 없음')
        return

    problems = []
    templates = 'backend/app/templates'

    # 1) 테마 목록마다 템플릿이 있고, 모든 테마가 탭과 모음 본문을 부른다. 테마 목록은 PHP 와 같아야 한다.
    themes = _py_literal('backend/app/models/guide_model.py', function='themes') or {}
    php_model = read('portal/application/models/Guide_model.php')
    php_block = re.search(r'function themes\(\).*?return array\((.*?)\);', php_model, re.S)
    php_themes = re.findall(r"'([a-z]+)'\s*=>\s*array\('name'", php_block.group(1)) if php_block else []
    if list(themes) != php_themes:
        problems.append(f'테마 목록이 PHP 와 다릅니다: {list(themes)} / {php_themes}')
    for key in themes:
        path = f'{templates}/guide/themes/{key}.html'
        if not os.path.isfile(os.path.join(ROOT, path)):
            problems.append(f'테마 "{key}" 의 템플릿 {path} 가 없습니다')
            continue
        text = read(path)
        for part in ('guide/_tabs.html', 'guide/_collection.html'):
            if part not in text:
                problems.append(f'{path} 가 {part} 를 부르지 않습니다')

    # 2) 유튜브 iframe 은 _video.html 한 곳에서만, nocookie 주소로만 만든다. 가이드 템플릿은 |safe 로 이스케이프를 끄지 않는다.
    for rel, full in walk(templates, ('.html',)):
        text = open(full, encoding='utf-8').read()
        if 'youtube' in text and '/embed/' in text and not rel.endswith('templates/guide/_video.html'):
            problems.append(f'{rel} 유튜브 iframe 은 guide/_video.html 을 불러 그립니다')
        if rel.startswith(templates + '/guide') and '|safe' in text:
            problems.append(f'{rel} 공개 가이드 템플릿에서 |safe 를 쓰지 않습니다(관리자가 쓴 글을 그대로 내보냄)')
    if 'youtube-nocookie.com/embed/' not in read(f'{templates}/guide/_video.html'):
        problems.append('guide/_video.html 이 youtube-nocookie.com/embed/ 주소를 쓰지 않습니다')

    # 3) 모음 주소는 글 주소보다 먼저 등록하고 RESERVED_SLUGS 에 넣는다. 조직 코드 하나만 받는 주소는 맨 마지막에 등록한다.
    router = read('backend/app/routers/guide.py')
    article_at = router.find("'/{org_code}/guide/{slug}'")
    reserved = _py_literal('backend/app/models/guide_model.py', name='RESERVED_SLUGS') or ()
    for word in re.findall(r"'/\{org_code\}/guide/([a-z][a-z0-9_-]*)'", router):
        if router.find(f"'/{{org_code}}/guide/{word}'") > article_at:
            problems.append(f'routers/guide.py: /guide/{word} 는 /guide/{{slug}} 보다 먼저 둡니다')
        if word not in reserved:
            problems.append(f'모음 주소 "{word}" 가 guide_model.RESERVED_SLUGS 에 없습니다')
    includes = re.findall(r'^app\.include_router\((.*?)\)', read('backend/app/main.py'), re.M)
    if not includes or includes[-1] != 'customer.org_router':
        problems.append('main.py: 조직 코드 주소(customer.org_router)를 맨 마지막에 등록하지 않았습니다(CLAUDE.md 3.8)')

    # 4) 두 서버가 같아야 하는 목록: 가이드 매칭 설정, 소개 페이지 아이콘. 옮긴 템플릿의 아이콘도 목록에 있어야 한다.
    php_match = read('portal/application/config/guide_match.php')
    for key, name in (('suffixes', 'SUFFIXES'), ('stopwords', 'STOPWORDS')):
        if _php_config_array(php_match, key) != _py_literal('backend/app/settings/guide_match.py', name=name):
            problems.append(f'settings/guide_match.py {name} 가 PHP config/guide_match.php 와 다릅니다')
    py_icons = _py_literal('backend/app/routers/home.py', function='landing_icons') or {}
    py_declared = {v for group in py_icons.values() for v in group.values()}
    if py_declared != declared_icons():
        problems.append('routers/home.py landing_icons() 가 PHP Home::landing_icons() 와 다릅니다')
    used = set()
    for rel, full in walk(templates, ('.html',)):
        used |= set(re.findall(r'material-symbols-outlined[^>]*>\s*([a-z][a-z0-9_]+)\s*<', open(full, encoding='utf-8').read()))
    if used - py_declared:
        problems.append('템플릿 아이콘이 landing_icons() 에 없습니다: ' + ', '.join(sorted(used - py_declared)))

    # 5) PHP 에서 뽑은 데이터 파일(엔티티 표, PCRE 유니코드 구간)이 있어야 xss_clean 과 가이드 매칭이 PHP 와 같다.
    for rel in ('backend/app/core/data/php_html5_entities.json', 'backend/app/core/data/php_pcre_unicode.json'):
        if not os.path.isfile(os.path.join(ROOT, rel)):
            problems.append(f'{rel} 가 없습니다')

    if problems:
        fail('FastAPI 화면 규칙 위반', '\n    '.join(problems[:12]))
    else:
        ok('FastAPI 화면 규칙 확인 (테마 템플릿·탭, 유튜브 한 곳, 모음/조직 주소 순서, PHP 와 같은 매칭 설정·아이콘, 데이터 파일)')


def check_fastapi_chat():
    """FastAPI 의 상담 위젯·실시간 규칙(CLAUDE.md 3.9, 3.11, 3.16, 3.18). PHP 검사와 같은 내용을 backend 에 적용한다."""
    if not os.path.isfile(os.path.join(ROOT, 'backend/app/routers/widget.py')):
        ok('FastAPI 위젯 없음')
        return

    problems = []
    py_files = list(walk('backend/app', ('.py',), ('__pycache__',)))

    # 1) 운영 여부는 chat_hours_model.availability() 안에서만 본다.
    allowed = ('backend/app/models/chat_model.py', 'backend/app/models/chat_hours_model.py')
    for rel, full in py_files:
        if rel in allowed:
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if re.search(r'\bhas_available_agent\(', line) and 'lambda' not in line:
                problems.append(f'{rel}:{number} 운영 여부는 chat_hours_model.availability() 로 판단합니다')

    # 2) 인사말·자동 응답·가이드 추천은 send() 가 발행할 메시지를 읽기 전에 저장한다(3.11, 3.18).
    widget = read('backend/app/routers/widget.py')
    block = re.search(r'\ndef send\(.*?(?=\n@router|\ndef |\Z)', widget, re.S)
    if block:
        body = block.group(0)
        listing = body.find('chat_model.list_messages(conversation.id, first_id - 1)')
        for part in ("'type': 'greeting'", '_auto_reply(', '_suggest_guide('):
            at = body.find(part)
            if at < 0 or listing < 0 or at > listing:
                problems.append(f'widget.send 에서 {part} 가 발행할 메시지를 읽기(list_messages) 전에 있어야 합니다')
        if 'realtime.publish_message(' not in body:
            problems.append('widget.send 가 저장한 메시지를 발행하지 않습니다(realtime.publish_message)')
    else:
        problems.append('routers/widget.py 의 send() 를 찾지 못했습니다')
    init_block = re.search(r'\ndef init\(.*?(?=\n@router|\ndef |\Z)', widget, re.S)
    if init_block and 'touch_visitor_seen' in init_block.group(0):
        problems.append('widget.init 에서 touch_visitor_seen 을 부르지 않습니다(창을 닫아 둔 방문자도 보는 중으로 셈)')

    # 3) 담당 변경, 보는 중/입력 중 이벤트는 조직 방(publish_org)에만 보낸다(고객 위젯으로 새지 않게).
    for rel, full in py_files:
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"'type':\s*'(presence|assignment)'", text):
            call = text.rfind('publish_', 0, match.start())
            if call < 0 or not text.startswith('publish_org(', call):
                number = text.count('\n', 0, match.start()) + 1
                problems.append(f'{rel}:{number} {match.group(1)} 이벤트는 realtime.publish_org() 로만 보냅니다')

    # 4) visitors 는 visitor_model 만 고친다.
    write = re.compile(r"""db\.(update|insert)\(\s*'visitors'|(UPDATE|INSERT\s+INTO)\s+`?visitors`?\b""", re.I)
    for rel, full in py_files:
        if rel == 'backend/app/models/visitor_model.py':
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if write.search(line):
                problems.append(f'{rel}:{number} visitors 는 visitor_model 로만 고칩니다(연락처 검사 clean_contact)')

    # 5) 상담원 답장을 저장하는 경로는 publish_new_messages() 를 거친다(팔로업 메일 판단 포함).
    for rel, full in walk('backend/app/routers', ('.py',)):
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"add_message\([^)]*?'agent'", text):
            start = text.rfind('\ndef ', 0, match.start())
            end = text.find('\ndef ', match.end())
            if 'publish_new_messages(' not in text[start:end if end > 0 else None]:
                number = text.count('\n', 0, match.start()) + 1
                problems.append(f'{rel}:{number} 상담원 답장을 저장했으면 publish_new_messages() 로 발행합니다')

    # 5-1) publish_new_messages() 는 팔로업 메일 판단(chat_followup.after_agent_message)을 거친다.
    console_py = os.path.join(ROOT, 'backend/app/routers/console.py')
    if os.path.isfile(console_py):
        block = re.search(r'\ndef publish_new_messages\(.*?(?=\n@router|\ndef |\Z)', read('backend/app/routers/console.py'), re.S)
        if not block or 'chat_followup.after_agent_message(' not in block.group(0):
            problems.append('console.publish_new_messages() 가 chat_followup.after_agent_message() 를 불러야 합니다')

    # 5-2) macros 는 macro_model 만 고친다(검사 clean_macro, 이름 중복 owner_key, 추천 색인 macro_terms 를 함께 처리).
    macro_write = re.compile(r"""db\.(update|insert)\(\s*'(macros|macro_terms)'|(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?(macros|macro_terms)`?\b""", re.I)
    for rel, full in py_files:
        if rel == 'backend/app/models/macro_model.py':
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if macro_write.search(line):
                problems.append(f'{rel}:{number} macros 는 macro_model 로만 고칩니다(CLAUDE.md 3.17)')

    # 5-3) 매크로 설정은 PHP config/macro.php 와 같아야 한다(전환 기간).
    if os.path.isfile(os.path.join(ROOT, 'backend/app/settings/macro.py')):
        php_macro = read('portal/application/config/macro.php')
        for key in ('max_org', 'max_personal', 'max_favorites', 'name_max', 'body_max', 'keywords_max',
                    'import_max_rows', 'max_terms', 'suggest_min_score', 'suggest_limit'):
            php_val = re.search(r"\$config\['" + key + r"'\]\s*=\s*([0-9]+)\s*;", php_macro)
            py_val = _py_literal('backend/app/settings/macro.py', name=key.upper())
            if not php_val or py_val != int(php_val.group(1)):
                problems.append(f'settings/macro.py {key.upper()} 가 PHP config/macro.php 와 다릅니다')
        php_vars = re.findall(r"'(\w+)'\s*=>\s*'([^']*)'", re.search(r"\$config\['variables'\] = array\((.*?)\);", php_macro, re.S).group(1))
        if dict(php_vars) != _py_literal('backend/app/settings/macro.py', name='VARIABLES'):
            problems.append('settings/macro.py VARIABLES 가 PHP config/macro.php 와 다릅니다')

    # 6) CSRF 제외 경로는 PHP 설정과 같아야 한다(위젯 API 는 외부 도메인에서 온다).
    php_ex = re.search(r"\$config\['csrf_exclude_uris'\] = array\((.*?)\);", read('portal/application/config/config.php'), re.S)
    php_ex = re.findall(r"'([^']+)'", php_ex.group(1)) if php_ex else []
    py_ex = _py_literal('backend/app/core/csrf.py', name='EXCLUDE_URIS')
    if list(py_ex or ()) != php_ex:
        problems.append(f'core/csrf.py EXCLUDE_URIS 가 PHP csrf_exclude_uris 와 다릅니다: {py_ex} / {php_ex}')

    if problems:
        fail('FastAPI 상담 규칙 위반', '\n    '.join(problems[:12]))
    else:
        ok('FastAPI 상담 규칙 확인 (availability 한 곳, 발행 전 저장, 조직 방 이벤트, visitors·macros 는 모델만, 답장 팔로업, 매크로 설정, CSRF 제외 경로)')



def check_fastapi_admin():
    """FastAPI 로 옮긴 조직 관리·가이드 관리 규칙(CLAUDE.md 3.6, 3.14, 3.15, 3.19)."""
    if not os.path.isfile(os.path.join(ROOT, 'backend/app/routers/admin_guide.py')):
        ok('FastAPI 조직 관리 없음')
        return

    problems = []
    py_files = list(walk('backend/app', ('.py',), ('__pycache__',)))

    # 1) 조직 관리 경로는 모두 조직 관리자만(require_admin) 쓴다.
    for rel in ('backend/app/routers/admin.py', 'backend/app/routers/admin_guide.py'):
        text = read(rel)
        for match in re.finditer(r"@router\.api_route\('([^']*)'.*?\n(?:async )?def \w+\((.*?)\):", text, re.S):
            if 'Depends(require_admin)' not in match.group(2):
                problems.append(f"{rel} {match.group(1) or '/'} 는 Depends(require_admin) 로 막습니다")

    # 1-1) 플랫폼 운영자 경로는 모두 운영자만(require_operator) 쓴다(CLAUDE.md 3.6).
    if os.path.isfile(os.path.join(ROOT, 'backend/app/routers/operator.py')):
        text = read('backend/app/routers/operator.py')
        for match in re.finditer(r"@router\.api_route\('([^']*)'.*?\n(?:async )?def \w+\((.*?)\):", text, re.S):
            if 'Depends(require_operator)' not in match.group(2):
                problems.append(f"backend/app/routers/operator.py {match.group(1) or '/'} 는 Depends(require_operator) 로 막습니다")

    # 2) 가이드 표는 guide_model 만 고친다(검사 clean_article, 색인 rebuild_index 를 함께 처리).
    guide_write = re.compile(r"""db\.(update|insert)\(\s*'guide_(articles|categories|synonyms|terms)'|(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?guide_(articles|categories|synonyms|terms)`?\b""", re.I)
    for rel, full in py_files:
        if rel == 'backend/app/models/guide_model.py':
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if guide_write.search(line):
                problems.append(f'{rel}:{number} 가이드 글·분류·동의어·색인은 guide_model 로만 고칩니다(CLAUDE.md 3.15)')

    # 3) 서버가 외부 주소로 요청하는 곳은 guide_model.fetch_external_image() 하나다(SSRF 검사). 내부 발행(realtime)과 메일은 예외.
    allowed = {'backend/app/models/guide_model.py': ('http.client', 'socket', 'ssl'),
               'backend/app/libraries/realtime.py': ('httpx',),
               'backend/app/libraries/mailer.py': ('smtplib', 'ssl')}
    net = re.compile(r'^\s*(?:import|from)\s+(httpx|requests|urllib\.request|http\.client|socket|ssl|smtplib|aiohttp|pycurl)\b', re.M)
    for rel, full in py_files:
        for match in net.finditer(open(full, encoding='utf-8').read()):
            if match.group(1) not in allowed.get(rel, ()):
                problems.append(f'{rel} 에서 {match.group(1)} 를 씁니다. 외부 요청은 guide_model.fetch_external_image() 를 거칩니다')
    model = read('backend/app/models/guide_model.py')
    block = re.search(r'\ndef fetch_external_image\(.*?(?=\n\ndef |\Z)', model, re.S)
    if not block or not all(part in block.group(0) for part in ('is_external_image(', 'public_ipv4(', 'store_fetched_image(', 'FETCH_MAX_REDIRECTS')):
        problems.append('fetch_external_image() 는 매번 is_external_image / public_ipv4 로 다시 검사하고 store_fetched_image 로 저장합니다')
    if 'image_file_ext(body)' not in model:
        problems.append('store_fetched_image() 는 올린 이미지와 같은 image_file_ext() 검사를 거칩니다')

    # 4) 이미지 업로드 경로는 check_uploaded_image() 로 검사한다(내용 기준 형식 판별, SVG 거부).
    for rel, field in (('backend/app/routers/admin.py', 'logo'), ('backend/app/routers/admin_guide.py', 'image')):
        if f"check_uploaded_image(post.files.get('{field}')" not in read(rel):
            problems.append(f'{rel} 의 {field} 업로드는 check_uploaded_image() 로 검사합니다')

    # 5) 샘플 가이드와 공휴일 후보는 PHP 설정과 같아야 한다(전환 기간).
    samples = read('portal/application/config/guide_samples.php')
    for key, name in (('categories', 'CATEGORIES'), ('synonyms', 'SYNONYMS'), ('articles', 'ARTICLES')):
        if _php_config(samples, key) != _py_literal('backend/app/settings/guide_samples.py', name=name):
            problems.append(f'settings/guide_samples.py {name} 가 PHP config/guide_samples.php 와 다릅니다')
    if _php_config(read('portal/application/config/holidays.php'), 'holidays') != _py_literal('backend/app/settings/holidays.py', name='HOLIDAYS'):
        problems.append('settings/holidays.py HOLIDAYS 가 PHP config/holidays.php 와 다릅니다')

    if problems:
        fail('FastAPI 조직 관리 규칙 위반', '\n    '.join(problems[:12]))
    else:
        ok('FastAPI 조직 관리 규칙 확인 (require_admin, require_operator, 가이드 표는 guide_model 만, 외부 요청 한 곳, 업로드 검사, 샘플·공휴일 설정)')


# ------------------------------------------------------------------ 실행 점검
# ------------------------------------- 3.13 고객에게 보내는 주소
def check_customer_url():
    """고객 접속 주소와 만족도 조사 주소를 base_url() 로 만들면 상담원이 연 주소(localhost, 내부 IP)가 나간다."""
    pattern = re.compile(r"base_url\(\s*\$org->org_code\s*(\)|\.\s*'/survey)")
    found = []

    for rel_dir in ('portal/application/views', 'portal/application/controllers'):
        for rel, full in walk(rel_dir, ('.php',), ('vendor',)):
            for number, line in enumerate(open(full, encoding='utf-8'), 1):
                if pattern.search(line):
                    found.append(f'{rel}:{number}')

    if found:
        fail('고객에게 보내는 주소를 base_url() 로 만들었습니다 (CLAUDE.md 3.13)',
             'customer_url($org->org_code) 를 쓰세요\n    ' + '\n    '.join(found[:10]))
    else:
        ok('고객 접속 주소가 customer_url() 을 거칩니다')


# ------------------------------------- 3.14 이미지 선택은 Dropzone
def check_asset_version():
    """뷰의 포털 JS/CSS 는 asset_url() 로 버전을 붙인다. base_url() 로 바로 쓰면 Cloudflare 가 예전 파일을 내준다."""
    plain = re.compile(r"""base_url\(\s*['"]assets/[^'"]*\.(?:js|css)['"]\s*\)""")
    found = []

    for rel, full in walk('portal/application/views', ('.php',), ()):
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if plain.search(line):
                found.append(f'{rel}:{number}')

    if found:
        fail('JS/CSS 주소에 버전이 없습니다 (CLAUDE.md 3.1)',
             "base_url('assets/...js') 대신 asset_url('assets/...js') 를 씁니다\n    " + '\n    '.join(found[:10]))
    else:
        ok('뷰의 JS/CSS 주소가 모두 asset_url() 로 버전을 붙입니다')


def check_image_drop():
    """이미지 선택은 RHImageDrop(Dropzone.js) 만 쓴다. 파일 입력을 직접 두거나 라이브러리 없이 부르면 안 된다."""
    file_input = re.compile(r"""<input[^>]*type\s*=\s*["']?file|type\s*[:=]\s*["']file["']""", re.I)
    found = []

    for rel_dir, suffixes in (('portal/application/views', ('.php',)), ('portal/assets/js', ('.js',))):
        for rel, full in walk(rel_dir, suffixes, ('vendor',)):
            for number, line in enumerate(open(full, encoding='utf-8'), 1):
                if file_input.search(line):
                    found.append(f'{rel}:{number} 파일 입력을 직접 씀')

    # RHImageDrop 을 쓰는 화면의 컨트롤러는 use_dropzone 을 넘겨야 라이브러리가 실린다.
    controllers = ''.join(open(full, encoding='utf-8').read()
                          for _, full in walk('portal/application/controllers', ('.php',), ()))
    for rel, full in walk('portal/application/views', ('.php',), ()):
        if 'RHImageDrop.create' in open(full, encoding='utf-8').read():
            view = rel.split('views/', 1)[-1][:-4]
            if not re.search(r"render\('" + re.escape(view) + r"'[^;]*'use_dropzone'\s*=>\s*TRUE", controllers, re.S):
                found.append(f'{rel} RHImageDrop 을 쓰지만 컨트롤러가 use_dropzone 을 넘기지 않음')

    if found:
        fail('이미지 선택이 Dropzone 규칙을 벗어났습니다 (CLAUDE.md 3.14)',
             'RHImageDrop.create() 를 쓰고 컨트롤러에서 use_dropzone => TRUE\n    ' + '\n    '.join(found[:10]))
    else:
        ok('이미지 선택이 모두 Dropzone(RHImageDrop) 을 거칩니다')


# ------------------------------------- 3.15 가이드
def check_guide():
    """가이드는 고객 화면에 관리자가 쓴 글을 그대로 내보내므로 조용히 깨지면 위험한 것들을 본다."""
    problems = []

    # 1) 유튜브는 개인정보 보호 강화 모드 주소로만 만든다.
    for rel_dir, suffixes in (('portal/application/views', ('.php',)), ('portal/assets/js', ('.js',))):
        for rel, full in walk(rel_dir, suffixes, ('vendor',)):
            for number, line in enumerate(open(full, encoding='utf-8'), 1):
                if re.search(r'(?<!-nocookie)\.com/embed/', line) and 'youtube' in line and 'youtube-nocookie.com/embed/' not in line:
                    problems.append(f'{rel}:{number} 유튜브 임베드는 youtube-nocookie.com/embed/ 로만 만듭니다')

    # 2) 공개 가이드 뷰는 값을 이스케이프 없이 내보내지 않는다.
    allowed = ('html_escape(', 'guide_text_html(', 'base_url(', '(int)', 'json_encode(')
    for rel, full in walk('portal/application/views/guide', ('.php',)):
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            for expr in re.findall(r'<\?=\s*(.*?)\s*\?>', line):
                if not expr.startswith(allowed):
                    problems.append(f'{rel}:{number} 이스케이프 없이 출력: <?= {expr[:40]} ?>')

    # 3) 가이드 카드 메시지를 저장하면 발행도 한다(3.11).
    console = read('portal/application/controllers/Console.php')
    block = re.search(r'function api_chat_send_guide\(\).*?\n    \}', console, re.S)
    if block and 'publish_new_messages' not in block.group(0):
        problems.append('Console::api_chat_send_guide 가 저장한 카드를 발행하지 않습니다 (publish_new_messages)')

    # 4) 테마 목록(Guide_model::themes)의 키마다 뷰 파일이 있어야 한다. 없으면 그 테마를 고른 조직의 공개 화면이 깨진다.
    model = read('portal/application/models/Guide_model.php')
    block = re.search(r'function themes\(\).*?return array\((.*?)\);', model, re.S)
    if block:
        for key in re.findall(r"'([a-z]+)'\s*=>\s*array\('name'", block.group(1)):
            if not os.path.isfile(os.path.join(ROOT, f'portal/application/views/guide/themes/{key}.php')):
                problems.append(f'테마 "{key}" 의 뷰 views/guide/themes/{key}.php 가 없습니다')
    else:
        problems.append('Guide_model::themes() 를 찾지 못했습니다')

    # 5) 모음 페이지(docs/guide-collections-plan.md)
    #    - 라우트 (:any)/guide/{낱말} 은 글 주소 규칙 (:any)/guide/(:any) 보다 위여야 닿는다.
    #    - 그 낱말은 Guide_model::RESERVED_SLUGS 에 있어야 같은 주소의 글이 생기지 않는다.
    #    - 모든 테마 뷰가 탭(_tabs)과 모음 본문(_collection)을 부른다. 빠지면 그 테마만 모음 페이지가 목록으로 보인다.
    #    - 유튜브 iframe 은 _video.php 한 곳에서만 만든다(주소 규칙이 한 곳에만 있게).
    routes = read('portal/application/config/routes.php').splitlines()
    article_rule = next((i for i, l in enumerate(routes) if re.match(r"\s*\$route\['\(:any\)/guide/\(:any\)'\]", l)), None)
    words = []
    for index, line in enumerate(routes):
        match = re.match(r"\s*\$route\['\(:any\)/guide/([a-z][a-z0-9_-]*)'\]", line)
        if match:
            words.append(match.group(1))
            if article_rule is not None and index > article_rule:
                problems.append(f"routes.php {index + 1}행: (:any)/guide/{match.group(1)} 는 (:any)/guide/(:any) 보다 위에 둡니다")
    reserved = re.search(r'const RESERVED_SLUGS = array\((.*?)\);', model, re.S)
    reserved = re.findall(r"'([^']+)'", reserved.group(1)) if reserved else []
    for word in words:
        if word not in reserved:
            problems.append(f'모음 주소 "{word}" 가 Guide_model::RESERVED_SLUGS 에 없습니다(같은 주소의 글을 만들 수 있게 됨)')
    for rel, full in walk('portal/application/views/guide/themes', ('.php',)):
        text = open(full, encoding='utf-8').read()
        for part in ('guide/_tabs', 'guide/_collection'):
            if part not in text:
                problems.append(f'{rel} 가 {part} 를 부르지 않습니다')
    for rel, full in walk('portal/application/views', ('.php',)):
        if rel.endswith('views/guide/_video.php'):
            continue
        if 'youtube-nocookie.com/embed/' in open(full, encoding='utf-8').read():
            problems.append(f'{rel} 유튜브 iframe 은 views/guide/_video.php 를 불러 그립니다')

    # 6) 올린 이미지는 git 에 넣지 않는다.
    if os.path.isdir(os.path.join(ROOT, 'portal/assets/uploads/guides')) and \
            'portal/assets/uploads/guides/*' not in read('.gitignore'):
        problems.append('.gitignore 에 portal/assets/uploads/guides/* 가 없습니다')

    if problems:
        fail('가이드 규칙 위반 (CLAUDE.md 3.15)', '\n    '.join(problems[:10]))
    else:
        ok('가이드 규칙 확인 (nocookie 임베드, 출력 이스케이프, 카드 발행, 테마 뷰, 모음 주소/탭, 업로드 제외)')


# ---------------------------------------------------------------- 3.16 상담 운영 상태
def check_chat_operation():
    """운영 여부를 여러 곳에서 따로 판단하면 위젯, 자동 응답, 부재 메일이 서로 다른 상태를 말한다."""
    problems = []

    # 1) 상담원 응대 여부는 Chat_hours_model::availability() 안에서만 본다.
    allowed = ('portal/application/models/Chat_model.php', 'portal/application/models/Chat_hours_model.php')
    for rel, full in walk('portal/application', ('.php',), ('cache', 'logs')):
        if rel in allowed:
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if re.search(r'->(has_available_agent|has_online_agent)\(', line):
                problems.append(f'{rel}:{number} 운영 여부는 chat_hours_model->availability() 로 판단합니다')

    # 2) 자동 응답은 send() 가 발행하는 범위 안에서 저장한다(3.11). 발행할 메시지를 읽기 전에 넣어야 한다.
    widget = read('portal/application/controllers/Widget.php')
    block = re.search(r'function send\(\).*?\n    \}', widget, re.S)
    if block:
        body = block.group(0)
        reply, listing = body.find('->auto_reply('), body.find('->list_messages(')
        if reply < 0 or listing < 0 or reply > listing:
            problems.append('Widget::send 에서 auto_reply() 가 발행할 메시지를 읽기(list_messages) 전에 있어야 합니다')
    else:
        problems.append('Widget::send() 를 찾지 못했습니다')

    # 3) 담당 변경, 보는 중/입력 중 이벤트에는 상담원 이름이 들어 있으므로 조직 방(publish_org)에만 보낸다.
    #    publish_conversation / publish_message 는 방문자 방(conv:)에도 가서 고객 위젯으로 샌다.
    for rel, full in walk('portal/application/controllers', ('.php',)):
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"'type'\s*=>\s*'(presence|assignment)'", text):
            call = text.rfind('->publish', 0, match.start())
            if call < 0 or not text.startswith('->publish_org(', call):
                number = text.count('\n', 0, match.start()) + 1
                problems.append(f'{rel}:{number} {match.group(1)} 이벤트는 realtime->publish_org() 로만 보냅니다(고객 방으로 새지 않게)')

    if problems:
        fail('상담 운영 상태 규칙 위반 (CLAUDE.md 3.16)', '\n    '.join(problems[:10]))
    else:
        ok('상담 운영 상태를 한 곳(availability)에서 판단하고 자동 응답을 발행합니다, 상담원 이벤트는 조직 방에만')



# ---------------------------------------------------------------- 3.18 인사말·연락처·팔로업
def check_followup():
    """상담원 답장이 팔로업 판단을 건너뛰면 자리를 비운 고객이 답을 영영 못 본다. 인사말은 발행 범위 안에서 저장한다."""
    problems = []

    # 1) 상담원 답장(sender 'agent')을 저장한 함수는 publish_new_messages() 를 부르고,
    #    publish_new_messages() 안에서 Chat_followup 이 판단한다(3.11 발행과 한 길).
    for rel, full in walk('portal/application/controllers', ('.php',)):
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"add_message\([^;]*?'agent'", text):
            start = text.rfind('function ', 0, match.start())
            end = text.find('\n    }', match.end())
            if '->publish_new_messages(' not in text[start:end]:
                number = text.count('\n', 0, match.start()) + 1
                problems.append(f'{rel}:{number} 상담원 답장을 저장했으면 publish_new_messages() 로 발행합니다(팔로업 메일 판단 포함)')

    console = read('portal/application/controllers/Console.php')
    block = re.search(r'function publish_new_messages\(.*?\n    \}', console, re.S)
    if not block or 'chat_followup->after_agent_message(' not in block.group(0):
        problems.append('Console::publish_new_messages() 가 chat_followup->after_agent_message() 를 불러야 합니다')

    # 2) 인사말은 첫 메시지 앞에 저장하고 send() 가 함께 발행한다. 발행할 메시지를 읽기 전에 넣어야 한다.
    widget = read('portal/application/controllers/Widget.php')
    block = re.search(r'function send\(\).*?\n    \}', widget, re.S)
    if block:
        body = block.group(0)
        greet, listing = body.find("'type' => 'greeting'"), body.find('->list_messages(')
        if greet < 0 or listing < 0 or greet > listing:
            problems.append('Widget::send 에서 인사말 저장(meta type greeting)이 발행할 메시지를 읽기(list_messages) 전에 있어야 합니다')

    # 3) 방문자 연락처는 Visitor_model 만 고친다(입력 검사 clean_contact 하나, 수집 시각 함께 기록).
    write = re.compile(r"""->(update|insert|replace)\(\s*'visitors'|(UPDATE|INSERT\s+INTO)\s+`?visitors`?\b""", re.I)
    for rel, full in walk('portal/application', ('.php',), ('cache', 'logs')):
        if rel == 'portal/application/models/Visitor_model.php':
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if write.search(line):
                problems.append(f'{rel}:{number} visitors 는 Visitor_model 로만 고칩니다(연락처 검사 clean_contact)')

    if problems:
        fail('인사말·연락처·팔로업 규칙 위반 (CLAUDE.md 3.18)', '\n    '.join(problems[:10]))
    else:
        ok('상담원 답장이 모두 팔로업 판단을 거치고, 인사말은 발행 범위에서 저장, 연락처는 Visitor_model 만 고칩니다')

# ---------------------------------------------------------------- 3.17 매크로
def check_macro():
    """매크로는 Macro_model 만 고친다(검사·이름 중복·추천 색인). 화면은 매크로 글을 HTML 로 넣지 않는다."""
    problems = []

    # 1) macros 테이블을 바꾸는 코드는 Macro_model 안에만 있다.
    write = re.compile(r"""->(insert|update|delete|replace|insert_batch|update_batch)\(\s*'macros'|(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?macros`?\b""", re.I)
    for rel, full in walk('portal/application', ('.php',), ('cache', 'logs')):
        if rel == 'portal/application/models/Macro_model.php':
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if write.search(line):
                problems.append(f'{rel}:{number} macros 는 Macro_model 의 save()/delete() 로만 고칩니다')

    # 2) 매크로 이름·내용은 상담원이 쓴 글이다. innerHTML/.html() 에 값을 넣지 않고 textContent 로 그린다.
    html = re.compile(r"""innerHTML\s*[+]?=(?!\s*['"]{2}\s*;)|\.html\(\s*[^)\s]""")
    for rel, full in walk('portal/assets/js/console', ('.js',)):
        if not os.path.basename(rel).startswith('macro'):
            continue
        for number, line in enumerate(open(full, encoding='utf-8'), 1):
            if html.search(line):
                problems.append(f'{rel}:{number} 매크로 화면은 textContent 로 그립니다(innerHTML 에 값을 넣지 않음)')

    # 3) 매크로로 채운 글도 기존 전송 API 로 보낸다. 사용 기록은 거기서 남긴다.
    console = read('portal/application/controllers/Console.php')
    block = re.search(r'function api_chat_send\(\).*?\n    \}', console, re.S)
    if not block or 'record_use(' not in block.group(0) or 'publish_new_messages' not in block.group(0):
        problems.append('Console::api_chat_send 에 매크로 사용 기록(record_use)과 발행(publish_new_messages)이 함께 있어야 합니다')

    if problems:
        fail('매크로 규칙 위반 (CLAUDE.md 3.17)', '\n    '.join(problems[:10]))
    else:
        ok('매크로 규칙 확인 (Macro_model 만 저장, 화면 이스케이프, 기존 전송 API 로 사용 기록)')


def check_live():
    base = os.environ.get('PORTAL_BASE', 'http://localhost:8099')
    pages = ['/', '/login', '/signup', '/demo']

    for path in pages:
        try:
            with urllib.request.urlopen(base + path, timeout=5) as response:
                body = response.read().decode('utf-8', 'replace')
        except (urllib.error.URLError, OSError) as exc:
            warn(f'개발 서버에 접속할 수 없어 실행 점검을 건너뜁니다 ({exc})')
            return

        if 'A PHP Error' in body or 'Fatal error' in body:
            fail(f'{path} 에서 PHP 오류가 발생합니다')
            return

        # 그리드를 쓰는 화면인데 라이브러리를 안 불렀는지 확인한다.
        if 'RHGrid.create' in body and 'wgrid.global.js' not in body:
            fail(f'{path} 가 그리드를 쓰는데 use_grid 를 넘기지 않았습니다')
            return

        # 아이콘을 쓰는데 서브셋 요청에서 빠졌는지 확인한다.
        used = set(re.findall(r'material-symbols-outlined[^>]*>\s*([a-z][a-z0-9_]+)\s*<', body))
        requested = re.search(r'icon_names=([a-z0-9_,]+)', body)

        if used and requested:
            missing = sorted(used - set(requested.group(1).split(',')))

            if missing:
                fail(f'{path} 의 아이콘이 서브셋 요청에서 빠졌습니다', ', '.join(missing))
                return

    ok(f'실행 점검 통과 ({len(pages)}개 화면)')


def main():
    check_tailwind()
    check_locales()
    check_icons()
    check_utc()
    check_grid()
    check_headers()
    check_api_urls()
    check_routes()
    check_widget()
    check_terms()
    check_class_collision()
    check_secrets()
    check_no_vnc()
    check_tunnel_proxy()
    check_customer_url()
    check_asset_version()
    check_image_drop()
    check_guide()
    check_chat_operation()
    check_macro()
    check_followup()
    check_fastapi()
    check_fastapi_screens()
    check_fastapi_chat()
    check_fastapi_admin()

    if '--live' in sys.argv:
        check_live()

    print()
    for message in passed:
        print(f'  통과  {message}')

    for message, detail in warnings:
        print(f'  주의  {message}')
        if detail:
            print(f'        {detail}')

    for message, detail in errors:
        print(f'  실패  {message}')
        if detail:
            print(f'        {detail}')

    print()
    print(f'통과 {len(passed)} · 주의 {len(warnings)} · 실패 {len(errors)}')

    if errors:
        print('\nCLAUDE.md 3장의 규칙을 확인해 주세요.')
        return 1

    return 0


if __name__ == '__main__':
    sys.exit(main())
