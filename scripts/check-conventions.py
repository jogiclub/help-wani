#!/usr/bin/env python3
"""
파일 위치: scripts/check-conventions.py
역할: CLAUDE.md 3장의 규칙이 지켜졌는지 자동 점검한다.

조용히 깨지는 것들(사전 키 누락, 아이콘 목록 누락, CSS 미빌드, 한 곳을 거쳐야 하는 저장 경로 등)을 잡는 것이 목적이다.
새 규칙을 추가하면 여기에 검사도 함께 넣는다. 포털은 FastAPI(backend/)이며 앞단은 PHP 없는 Apache(docker/web)다.

사용법:
    python3 scripts/check-conventions.py          # 정적 검사
    python3 scripts/check-conventions.py --live   # 실행 중인 개발 서버까지 점검
"""
import ast
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

APP = 'backend/app'
TEMPLATES = 'backend/app/templates'
ROUTERS = 'backend/app/routers'
JS = 'backend/assets/js'

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


def exists(path):
    return os.path.exists(os.path.join(ROOT, path))


def walk(rel_dir, suffixes, skip=()):
    base = os.path.join(ROOT, rel_dir)

    for dirpath, dirnames, filenames in os.walk(base):
        dirnames[:] = [d for d in dirnames if not any(s in os.path.join(dirpath, d) for s in skip)]

        for name in filenames:
            if name.endswith(suffixes):
                full = os.path.join(dirpath, name)
                yield os.path.relpath(full, ROOT), full


def py_files():
    return list(walk(APP, ('.py',), ('__pycache__',)))


def lines_of(full):
    return enumerate(open(full, encoding='utf-8'), 1)


def py_literal(rel, function=None, name=None):
    """파이썬 파일에서 함수가 돌려주는 값(function) 또는 모듈 상수(name)를 읽는다(의존성 없이 ast 로)."""
    tree = ast.parse(read(rel))
    for node in tree.body:
        if function and isinstance(node, ast.FunctionDef) and node.name == function:
            for sub in ast.walk(node):
                if isinstance(sub, ast.Return) and sub.value is not None:
                    return ast.literal_eval(sub.value)
        if name and isinstance(node, ast.Assign) and any(getattr(t, 'id', None) == name for t in node.targets):
            return ast.literal_eval(node.value)
    return None


def py_function(rel, name):
    """모듈 최상위 함수 하나의 본문(다음 최상위 정의 전까지)."""
    text = read(rel)
    match = re.search(r'\n(?:async )?def ' + re.escape(name) + r'\(.*?(?=\n@|\n(?:async )?def |\nclass |\Z)', text, re.S)
    return match.group(0) if match else None


def template_text(view, seen=None):
    """템플릿과 그 템플릿이 고정 이름으로 include 하는 템플릿, asset_url 로 싣는 화면 JS 를 합친 글."""
    seen = seen if seen is not None else set()
    if view in seen or not os.path.isfile(os.path.join(ROOT, TEMPLATES, view)):
        return ''
    seen.add(view)
    text = read(f'{TEMPLATES}/{view}')
    out = [text]
    for inc in re.findall(r"""\{%-?\s*include\s+['"]([^'"]+)['"]""", text):
        out.append(template_text(inc, seen))
    for js in re.findall(r"""asset_url\(\s*['"]assets/(js/[^'"]+\.js)['"]""", text):
        if exists('backend/assets/' + js):
            out.append(read('backend/assets/' + js))
    return '\n'.join(out)


def render_calls():
    """라우터의 render(request, '뷰', {...}) 호출마다 (뷰, 인자 글, 파일:줄). 괄호를 세어 호출 끝을 찾는다."""
    calls = []
    for rel, full in walk(ROUTERS, ('.py',), ('__pycache__',)):
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"render\(\s*request\s*,\s*'([^']+)'", text):
            depth, i = 0, match.start() + len('render')
            while i < len(text):
                if text[i] == '(':
                    depth += 1
                elif text[i] == ')':
                    depth -= 1
                    if depth == 0:
                        break
                i += 1
            calls.append((match.group(1), text[match.start():i + 1], f'{rel}:{text.count(chr(10), 0, match.start()) + 1}'))
    return calls


# ------------------------------------------------- 3.1 Bootstrap 6 + 왔니
BOOTSTRAP_DIR = 'backend/assets/vendor/bootstrap/6.0.0-alpha.1'
# 공식 설치 문서(getbootstrap.com/docs/6.0/getting-started/install)의 무결성 값
BOOTSTRAP_SHA384 = {
    'bootstrap.min.css': 'B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe',
    'bootstrap.bundle.min.js': '1a/pXj49ZQ1aHEmrJ+gMw1otqoVsYwlEnlD8mIfY2TV03r20Y0CN7uqx1tQogjPL',
}
# Bootstrap 화면에 남으면 안 되는 5.3 이름과 예전(Tailwind 시절) 포털 전용 이름(조용히 모양이 사라진다)
BOOTSTRAP_LEGACY = re.compile(
    r"""data-bs-(?:toggle|dismiss)=["'](?:modal|offcanvas|dropdown)["']"""
    r"""|(?<![\w-])(?:offcanvas(?:-[a-z]+)?|dropdown-(?:menu|item|toggle)|form-select|text-muted"""
    r"""|modal-(?:root|dialog|content|header|body|footer))\b"""
    r"""|(?<![\w-])btn-(?:primary|secondary|success|danger|warning|info|ghost|outline-[a-z]+)\b"""
    r"""|(?<![\w-])badge-(?:gray|blue|amber|green|red|dark)\b|(?<![\w-])form-(?:input|help|error)\b"""
    r"""|data-(?:modal|drawer)-(?:close|backdrop)""")
# Tailwind 클래스. 2026-10-22 에 Tailwind 를 뺐으므로 어느 화면에서도 아무 모양을 내지 않는다(다시 들어오지 않게 막는다).
TAILWIND_ONLY = re.compile(
    r"""(?<![\w-])(?:bg|text|border|ring)-(?:slate|gray|zinc|neutral|stone|red|amber|emerald|blue|brand)-\d{2,3}\b"""
    r"""|(?<![\w-])(?:space-[xy]|divide-[xy])-\d\b|(?<![\w-])(?:sm|md|lg|xl):(?:flex|grid|hidden|inline|block|grid-cols-\d)\b"""
    r"""|(?<![\w-])max-w-(?:xs|sm|md|lg|xl|[2-7]xl)\b|(?<![\w-])text-(?:xs|sm|base|lg|xl|[2-9]xl)\b"""
    r"""|(?<![\w-])font-(?:mono|medium|semibold|bold)\b"""
    r"""|(?<![\w-])(?:truncate|leading-[a-z]+|tracking-[a-z]+|rounded-(?:md|lg|xl|full)|shadow-(?:md|xl))\b""")


def bootstrap_layouts(kit='bootstrap'):
    """ui_kit 을 kit('bootstrap' 또는 'plain')으로 정한 레이아웃 이름(layouts/xxx)."""
    names = []
    for rel, full in walk(f'{TEMPLATES}/layouts', ('.html',)):
        if re.search(r"""\{%-?\s*set\s+ui_kit\s*=\s*['"]""" + kit + r"""['"]""", open(full, encoding='utf-8').read()):
            names.append('layouts/' + os.path.basename(rel)[:-len('.html')])
    return sorted(names)


def bootstrap_files(layouts):
    """Bootstrap 레이아웃과 그 레이아웃으로 그리는 화면, 화면이 include 하는 템플릿, 화면이 싣는 JS."""
    files = {f'{TEMPLATES}/{layout}.html' for layout in layouts}
    constants = {}
    for view, call, where in render_calls():
        rel = where.rsplit(':', 1)[0]
        if rel not in constants:
            # _LAYOUT = 'layouts/operator' 처럼 모듈 상수로 넘기는 라우터도 있다.
            constants[rel] = dict(re.findall(r"""^(\w+)\s*=\s*['"](layouts/[a-z_]+)['"]""", read(rel), re.M))
        match = re.search(r"""['"](layouts/[a-z_]+)['"]""", call)
        layout = match.group(1) if match else 'layouts/main'
        for name, value in constants[rel].items():
            if re.search(r'[,(]\s*' + name + r'\s*\)\s*$', call):
                layout = value
        if layout not in layouts:
            continue
        # 화면과 그 화면이 include 하는 템플릿(여러 단계)을 따라가며, 각 템플릿이 싣는 화면 JS 도 넣는다.
        queue = [f'{view}.html']
        while queue:
            name = queue.pop()
            path = f'{TEMPLATES}/{name}'
            if path in files or not exists(path):
                continue
            files.add(path)
            text = read(path)
            for inc in re.findall(r"""\{%-?\s*include\s+['"]([^'"]+)['"]""", text):
                if not inc.startswith('layouts/'):
                    queue.append(inc)
            for js in re.findall(r"""asset_url\(\s*['"]assets/(js/[^'"]+\.js)['"]""", text):
                files.add('backend/assets/' + js)
    return sorted(f for f in files if exists(f))


def check_bootstrap():
    """Bootstrap 6 벤더 파일 무결성, bs.js 와 _head.html 의 버전 일치, Bootstrap 화면에 5.3·Tailwind 이름이 없는지."""
    import base64
    import hashlib

    problems = []

    for name, digest in BOOTSTRAP_SHA384.items():
        path = os.path.join(ROOT, BOOTSTRAP_DIR, name)
        if not os.path.isfile(path):
            problems.append(f'{BOOTSTRAP_DIR}/{name} 이 없음')
            continue
        actual = base64.b64encode(hashlib.sha384(open(path, 'rb').read()).digest()).decode()
        if actual != digest:
            problems.append(f'{BOOTSTRAP_DIR}/{name} 의 sha384 가 공식 값과 다름')

    head = read(f'{TEMPLATES}/layouts/_head.html')
    bs_js = read(f'{JS}/bs.js') if exists(f'{JS}/bs.js') else ''
    css_ver = set(re.findall(r'vendor/bootstrap/([^/]+)/bootstrap\.min\.css', head))
    js_ver = set(re.findall(r'vendor/bootstrap/([^/]+)/bootstrap\.bundle\.min\.js', bs_js))
    if not css_ver or css_ver != js_ver:
        problems.append(f'Bootstrap 버전이 어긋남: _head.html {sorted(css_ver)} / bs.js {sorted(js_ver)}')

    for rel, full in walk(JS, ('.js',), ('vendor',)):
        if re.search(r'window\.bootstrap\b|\bbootstrap\.(?:Dialog|Drawer|Toast|Tooltip|Menu|Tab)\b', open(full, encoding='utf-8').read()):
            problems.append(f'{rel} 가 bootstrap 전역을 씀(Bootstrap 6 은 전역이 없음, withBootstrap() 을 씁니다)')


    layouts = bootstrap_layouts()
    files = bootstrap_files(layouts)
    for rel in files:
        for number, line in lines_of(os.path.join(ROOT, rel)):
            for pattern, label in ((BOOTSTRAP_LEGACY, '5.3·예전 이름'), (TAILWIND_ONLY, 'Tailwind 전용 클래스')):
                found = pattern.search(line)
                if found:
                    problems.append(f'{rel}:{number} {label} "{found.group(0)}"')

    if problems:
        fail('Bootstrap 6 규칙 위반 (docs/frontend-bootstrap6-plan.md)', '\n    '.join(problems[:15]))
    else:
        ok(f'Bootstrap 6 확인 (무결성, 버전 일치, 레이아웃 {len(layouts)}개·파일 {len(files)}개에 5.3·Tailwind 이름 없음)')


# ------------------------------------------------- 고객 화면 (ui_kit 'plain', customer.css)
# 오래된 브라우저에서도 열려야 하므로(docs/frontend-bootstrap6-plan.md 4.4) 이 문법을 쓰지 않는다.
CUSTOMER_CSS_FORBIDDEN = re.compile(r'oklch|color-mix|light-dark|:has\(|\(\s*width\s*[<>]|\bdvh\b|@layer|@container')


def check_customer_css():
    """고객 화면은 Bootstrap·Tailwind 없이 customer.css 의 rhc-* 클래스만 쓰고, 그 CSS 는 오래된 브라우저 문법만 쓴다."""
    problems = []
    layouts = bootstrap_layouts('plain')
    if not layouts:
        fail('ui_kit = plain 인 고객 레이아웃이 없습니다', 'layouts/customer.html')
        return

    head = read(f'{TEMPLATES}/layouts/_head.html')
    plain = re.search(r"ui_kit == 'plain' %\}(.*?)\{% (?:else|elif|endif)", head, re.S)
    if not plain or 'bootstrap' in plain.group(1) or 'app.css' in plain.group(1) or 'customer.css' not in plain.group(1):
        problems.append("_head.html 의 plain 분기는 customer.css 만 실어야 함(Bootstrap·app.css 금지)")

    files = bootstrap_files(layouts)
    for rel in files:
        if not rel.endswith('.html'):
            continue
        for number, line in lines_of(os.path.join(ROOT, rel)):
            for attr in re.findall(r'class="([^"{}]*)"', line):
                for token in attr.split():
                    if not token.startswith('rhc-'):
                        problems.append(f'{rel}:{number} 고객 화면에 rhc- 가 아닌 클래스 "{token}"')
            if 'bi bi-' in line or 'data-bs-' in line:
                problems.append(f'{rel}:{number} 고객 화면에 Bootstrap 아이콘·속성')

    for number, line in lines_of(os.path.join(ROOT, 'backend/assets/css/customer.css')):
        if not line.lstrip().startswith(('*', '/*')):
            found = CUSTOMER_CSS_FORBIDDEN.search(line)
            if found:
                problems.append(f'customer.css:{number} 오래된 브라우저에서 깨지는 문법 "{found.group(0)}"')

    if problems:
        fail('고객 화면 CSS 규칙 위반 (docs/frontend-bootstrap6-plan.md 4.4)', '\n    '.join(problems[:15]))
    else:
        ok(f'고객 화면 확인 (레이아웃 {len(layouts)}개·파일 {len(files)}개가 customer.css 의 rhc-* 만 씀, 오래된 브라우저 문법)')


# ---------------------------------------------------------------- 3.1 자산 주소에 버전
def check_asset_version():
    """템플릿의 포털 JS/CSS 는 asset_url() 로 버전을 붙인다. base_url() 로 바로 쓰면 Cloudflare 가 예전 파일을 내준다."""
    plain = re.compile(r"""base_url\(\s*['"]assets/[^'"]*\.(?:js|css)['"]\s*\)""")
    found = [f'{rel}:{n}' for rel, full in walk(TEMPLATES, ('.html',)) for n, line in lines_of(full) if plain.search(line)]

    if found:
        fail('JS/CSS 주소에 버전이 없습니다 (CLAUDE.md 3.1)',
             "base_url('assets/...js') 대신 asset_url('assets/...js') 를 씁니다\n    " + '\n    '.join(found[:10]))
    else:
        ok('템플릿의 JS/CSS 주소가 모두 asset_url() 로 버전을 붙입니다')


# ------------------------------------------------------------------ 3.2 다국어
def check_locales():
    lang_dir = 'backend/assets/lang'
    files = sorted(f for f in os.listdir(os.path.join(ROOT, lang_dir)) if f.endswith('.json'))

    if not files:
        fail('언어 사전이 없습니다', lang_dir)
        return

    keys = {}
    for name in files:
        keys[name[:-5]] = set(json.loads(read(os.path.join(lang_dir, name))))

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
    icons = py_literal(f'{ROUTERS}/home.py', function='landing_icons')
    if not icons:
        return None
    return {value for group in icons.values() for value in group.values()}


BOOTSTRAP_ICONS_CSS = 'backend/assets/vendor/bootstrap-icons/1.13.2/bootstrap-icons.min.css'


def check_icons():
    """아이콘은 Bootstrap Icons(bi-*) 만 쓴다. 글꼴에 없는 이름은 빈칸이 되므로 글꼴 CSS 와 대조한다.

    landing_icons() 의 이름(템플릿이 'bi-' 를 붙임)과 템플릿·화면 JS 에 적힌 bi-이름을 모두 본다.
    Material Symbols 는 2026-10-22 에 뺐다(위젯은 인라인 SVG 라 해당 없음).
    """
    declared = declared_icons()

    if declared is None:
        fail('routers/home.py 의 landing_icons() 를 찾지 못했습니다')
        return
    if not exists(BOOTSTRAP_ICONS_CSS):
        fail('Bootstrap Icons 글꼴 CSS 가 없습니다', BOOTSTRAP_ICONS_CSS)
        return

    available = set(re.findall(r'\.bi-([a-z0-9-]+)::before', read(BOOTSTRAP_ICONS_CSS)))
    problems = [f'landing_icons() 의 "{name}"' for name in sorted(declared - available)]

    for rel, full in list(walk(TEMPLATES, ('.html',))) + list(walk(JS, ('.js',), ('vendor',))):
        if rel.endswith('widget.js'):
            continue
        for number, line in lines_of(full):
            if 'material-symbols' in line:
                problems.append(f'{rel}:{number} Material Symbols 를 씀(Bootstrap Icons 로 바꿉니다)')
            for name in re.findall(r'(?<![\w-])bi-([a-z0-9]+(?:-[a-z0-9]+)*)(?![\w-])', line):
                if name not in available:
                    problems.append(f'{rel}:{number} bi-{name}')

    if problems:
        fail('Bootstrap Icons 에 없는 아이콘이 있습니다 (CLAUDE.md 3.3)',
             '\n    '.join(problems[:15]) + '\n    없는 이름은 아이콘 자리가 비어 보입니다')
    else:
        ok(f'아이콘 확인 (Bootstrap Icons {len(available)}개 중 소개 페이지 {len(declared)}개와 화면 사용 이름 모두 있음)')


# -------------------------------------------------------------------- 3.4 시간
def check_utc():
    compose = read('docker-compose.yml')
    db_py = read(f'{APP}/db.py')

    if '--default-time-zone=+00:00' not in compose:
        fail('MySQL 이 UTC 로 설정되지 않았습니다', 'docker-compose.yml 의 --default-time-zone=+00:00')
    elif "time_zone = '+00:00'" not in db_py:
        fail('DB 세션이 UTC 로 설정되지 않았습니다', "backend/app/db.py 의 SET time_zone = '+00:00'")
    else:
        ok('시간 저장 기준이 UTC 입니다')


# ------------------------------------------------------------------ 3.5 wgrid
WGRID_DIR = 'backend/assets/vendor/wgrid/0.1.0'


def check_grid():
    # AG Grid 옵션 이름이 남아 있으면 wgrid 가 조용히 무시해 열이 비거나 이벤트가 안 걸린다.
    leftovers = ('agGrid', 'columnDefs', 'headerName', 'valueFormatter', 'cellRenderer',
                 'setGridOption', 'applyTransaction', 'getRowId', 'overlayNoRowsTemplate',
                 # wgrid Pro 는 운영 도메인에서 라이선스 키가 필요하다.
                 'wgridPro', 'pro.global.js')
    found = [f'{rel}: {name}' for rel, full in walk(JS, ('.js',))
             for name in leftovers if name in open(full, encoding='utf-8').read()]

    if found:
        fail('AG Grid 옵션이나 wgrid Pro 를 쓰고 있습니다', '\n    '.join(found))
        return

    missing = [name for name in ('wgrid.global.js', 'wgrid.css') if not exists(f'{WGRID_DIR}/{name}')]
    if missing:
        fail('wgrid 파일이 없습니다', f'{WGRID_DIR}/' + ', '.join(missing))
        return

    size = re.search(r'DEFAULT_PAGE_SIZE\s*=\s*(\d+)', read(f'{JS}/grid.js'))
    if not size or size.group(1) != '20':
        fail('그리드 기본 페이지 크기가 20 이 아닙니다', 'backend/assets/js/grid.js')
    else:
        ok('wgrid 설정 확인 (코어만, 기본 20개)')


# --------------------------------------- 3.5, 3.14 라이브러리 플래그(use_grid, use_dropzone)
def check_library_flags():
    """그리드·Dropzone 을 쓰는 화면은 라우터가 use_grid / use_dropzone 을 넘겨야 라이브러리가 실린다.

    템플릿, 그 템플릿이 include 하는 템플릿, asset_url 로 싣는 화면 JS 를 함께 본다.
    """
    problems = []
    file_input = re.compile(r"""<input[^>]*type\s*=\s*["']?file|type\s*[:=]\s*["']file["']""", re.I)

    # 이미지 선택은 RHImageDrop(Dropzone.js) 만 쓴다. 파일 입력을 직접 두지 않는다(3.14).
    for rel, full in list(walk(TEMPLATES, ('.html',))) + list(walk(JS, ('.js',), ('vendor',))):
        for number, line in lines_of(full):
            if file_input.search(line):
                problems.append(f'{rel}:{number} 파일 입력을 직접 씀(RHImageDrop.create 를 씁니다)')

    for view, call, where in render_calls():
        text = template_text(view + '.html')
        if 'RHImageDrop.create' in text and not re.search(r"'use_dropzone'\s*:\s*True", call):
            problems.append(f"{where} {view} 가 RHImageDrop 을 쓰는데 'use_dropzone': True 를 넘기지 않음")
        if 'RHGrid.create' in text and not re.search(r"'use_grid'\s*:\s*True", call):
            problems.append(f"{where} {view} 가 RHGrid 를 쓰는데 'use_grid': True 를 넘기지 않음")

    if problems:
        fail('그리드·이미지 선택 라이브러리 규칙 위반 (CLAUDE.md 3.5, 3.14)', '\n    '.join(problems[:10]))
    else:
        ok('그리드·Dropzone 화면이 라이브러리 플래그를 넘기고, 파일 입력을 직접 쓰지 않습니다')


# ------------------------------------------------------------- 파일 상단 주석
def check_headers():
    targets = (
        (APP, ('.py',), ('__pycache__',)),
        (TEMPLATES, ('.html',), ()),
        (JS, ('.js',), ('vendor',)),
        ('relay/src', ('.js',), ()),
        ('realtime/src', ('.js',), ()),
        ('scripts', ('.py', '.sh'), ()),
        ('launcher/RemoteHelp.App', ('.cs',), ('bin', 'obj')),
    )

    missing = []

    for rel_dir, suffixes, skip in targets:
        if not exists(rel_dir):
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


# ------------------------------------------------- 3.7 API 주소는 절대 경로로
def check_api_urls():
    """JS 가 API 주소를 상대 경로로 적으면 화면 깊이에 따라 404 가 난다."""
    pattern = re.compile(r"""api(?:Get|Post)\(\s*['"](?!https?://|/)""")
    found = [f'{rel}:{n}: {line.strip()[:70]}' for rel, full in walk(JS, ('.js',), ('vendor',))
             for n, line in lines_of(full) if pattern.search(line)]

    if found:
        fail('JS 에서 API 주소를 상대 경로로 적었습니다 (CLAUDE.md 3.7)',
             '\n    '.join(found[:10]) + '\n    템플릿에서 base_url() 로 만든 주소를 넘기세요')
    else:
        ok('JS API 주소가 모두 절대 경로입니다')


# ------------------------------------------------- 3.6 권한 의존성
def check_permissions():
    """조직 관리 경로는 require_admin, 운영자 경로는 require_operator 로만 막는다."""
    problems = []
    route = re.compile(r"@router\.api_route\('([^']*)'.*?\n(?:async )?def \w+\((.*?)\):", re.S)

    for rel, dep in ((f'{ROUTERS}/admin.py', 'require_admin'), (f'{ROUTERS}/admin_guide.py', 'require_admin'),
                     (f'{ROUTERS}/operator.py', 'require_operator')):
        if not exists(rel):
            problems.append(f'{rel} 가 없습니다')
            continue
        for match in route.finditer(read(rel)):
            if f'Depends({dep})' not in match.group(2):
                problems.append(f"{rel} {match.group(1) or '/'} 는 Depends({dep}) 로 막습니다")

    if problems:
        fail('권한 규칙 위반 (CLAUDE.md 3.6)', '\n    '.join(problems[:10]))
    else:
        ok('조직 관리 경로는 require_admin, 운영자 경로는 require_operator')


# ------------------------------------------------------------------ 3.8 라우팅
def check_routes():
    """조직 코드 하나만 받는 /{org_code} 는 맨 마지막에 등록한다. 공개 가이드 모음 주소는 글 주소보다 먼저, 글 주소로 못 쓰게 한다."""
    problems = []

    includes = re.findall(r'^app\.include_router\((.*?)\)', read(f'{APP}/main.py'), re.M)
    if not includes or includes[-1] != 'customer.org_router':
        problems.append('main.py: 조직 코드 주소(customer.org_router)를 맨 마지막에 등록하지 않았습니다')

    router = read(f'{ROUTERS}/guide.py')
    article_at = router.find("'/{org_code}/guide/{slug}'")
    reserved = py_literal(f'{APP}/models/guide_model.py', name='RESERVED_SLUGS') or ()
    for word in re.findall(r"'/\{org_code\}/guide/([a-z][a-z0-9_-]*)'", router):
        if router.find(f"'/{{org_code}}/guide/{word}'") > article_at:
            problems.append(f'routers/guide.py: /guide/{word} 는 /guide/{{slug}} 보다 먼저 둡니다')
        if word not in reserved:
            problems.append(f'모음 주소 "{word}" 가 guide_model.RESERVED_SLUGS 에 없습니다(같은 주소의 글을 만들 수 있게 됨)')

    if problems:
        fail('라우팅 순서 규칙 위반 (CLAUDE.md 3.8, 3.15)', '\n    '.join(problems))
    else:
        ok('라우팅 순서 확인 (조직 코드 맨 마지막, 모음 주소 먼저·예약)')


# ------------------------------------------------------------- 3.9 상담 위젯
def check_widget():
    """위젯은 고객사 사이트 안에서 도는 코드라 포털 자산을 쓰면 안 된다. 위젯 API 는 CSRF 에서 뺀다."""
    widget_js = os.path.join(ROOT, f'{JS}/widget.js')
    problems = []

    raw = open(widget_js, encoding='utf-8').read()
    # 규칙을 설명하는 주석에 금지어가 나오므로 주석을 걷어내고 본다.
    body = re.sub(r'//[^\n]*', '', re.sub(r'/\*.*?\*/', '', raw, flags=re.S))

    for needle, label in (('wani.css', '포털 CSS'), ('bootstrap.min', 'Bootstrap'), ('common.js', '포털 공통 스크립트'), ('i18n.js', '포털 번역 스크립트'),
                          ('RH_I18N', '포털 번역 전역 변수'), ('jQuery', 'jQuery')):
        if needle in body:
            problems.append(f'widget.js 가 {label}({needle}) 에 기대고 있습니다')

    if re.search(r'(?<![\w$])\$\(', body):
        problems.append('widget.js 에서 jQuery 형태의 $() 를 쓰고 있습니다')

    if 'attachShadow' not in raw:
        problems.append('widget.js 가 Shadow DOM(attachShadow) 을 쓰지 않습니다 — 고객사 CSS 와 충돌합니다')

    excludes = py_literal(f'{APP}/core/csrf.py', name='EXCLUDE_URIS') or ()
    if not any(e.startswith('api/widget') for e in excludes):
        problems.append("core/csrf.py EXCLUDE_URIS 에 'api/widget/.*' 가 없습니다 — 외부 도메인 호출이 막힙니다")

    if problems:
        fail('상담 위젯 규칙 위반 (CLAUDE.md 3.9)', '\n    '.join(problems))
    else:
        ok('상담 위젯 규칙 확인 (독립 실행, Shadow DOM, CSRF 제외)')


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

    for rel_dir, suffixes in ((TEMPLATES, ('.html',)), (ROUTERS, ('.py',)), (JS, ('.js',))):
        for rel, full in walk(rel_dir, suffixes, ('vendor', '__pycache__')):
            for number, line in lines_of(full):
                for wrong, right in banned.items():
                    if wrong in line:
                        found.append(f'{rel}:{number} "{wrong}" -> "{right}"')

    if found:
        fail('원격과 상담 용어가 섞였습니다 (CLAUDE.md 3.10)', '\n    '.join(found[:10]))
    else:
        ok('원격/상담 용어 구분 확인')


# -------------------------------------------------------------------- 비밀값
def check_secrets():
    tracked = subprocess.run(['git', 'ls-files', '.env'], cwd=ROOT, capture_output=True, text=True).stdout.strip()

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

        hit = subprocess.run(['git', 'grep', '-l', '--', value], cwd=ROOT, capture_output=True, text=True)

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

    for rel_dir, suffixes in ((APP, ('.py', '.html')), (JS, ('.js',)), ('relay/src', ('.js',)),
                              ('launcher/RemoteHelp.App', ('.cs',))):
        if not exists(rel_dir):
            continue
        for rel, full in walk(rel_dir, suffixes, ('bin', 'obj', 'vendor', '__pycache__')):
            found += [f'{rel}:{n}' for n, line in lines_of(full) if pattern.search(line)]

    if found:
        fail('VNC 시절 코드가 남아 있습니다', '\n    '.join(found[:10]))
    else:
        ok('VNC 잔재 없음')


# ------------------------------------------------- 3.12 고객 IP, 앞단 Apache
def check_proxy():
    """고객 IP 를 믿는 경로: cloudflared(고정 주소) → Apache(mod_remoteip) → X-RemoteHelp-Client-IP(set) → FastAPI(web 고정 주소만 믿음)."""
    compose = read('docker-compose.yml')
    vhost = read('docker/web/apache-vhost.conf')
    request_text = read(f'{APP}/core/request.py')
    problems = []

    trusted = [t for line in re.findall(r'^\s*RemoteIP(?:Internal|Trusted)Proxy\s+(.+)$', vhost, re.M) for t in line.split()]
    cloudflared = re.search(r'cloudflared:.*?ipv4_address:\s*([0-9.]+)', compose, re.S)
    if not cloudflared:
        problems.append('docker-compose.yml 의 cloudflared 에 ipv4_address(고정 주소)가 없습니다')
    elif any('/' in t for t in trusted):
        problems.append('apache-vhost.conf RemoteIP 신뢰 주소가 대역입니다(8099 직접 요청이 tunnel 게이트웨이로 들어와 위조 가능). cloudflared 주소 하나만 둡니다')
    elif trusted != [cloudflared.group(1)]:
        problems.append(f'apache-vhost.conf RemoteIP 신뢰 주소 {trusted} 가 cloudflared 주소 {cloudflared.group(1)} 와 다릅니다')

    web_ip = re.search(r'\n  web:.*?tunnel:\s*\n\s*ipv4_address:\s*([0-9.]+)', compose, re.S)
    api_ip = re.search(r'\n  api:.*?tunnel:\s*\n\s*ipv4_address:\s*([0-9.]+)', compose, re.S)
    trusted_proxy = re.search(r"^TRUSTED_PROXY_IP = '([0-9.]+)'", request_text, re.M)
    header = re.search(r"^CLIENT_IP_HEADER = '([a-z0-9-]+)'", request_text, re.M)

    if not trusted_proxy or not web_ip or trusted_proxy.group(1) != web_ip.group(1):
        problems.append('backend/app/core/request.py: TRUSTED_PROXY_IP 가 docker-compose.yml 의 web tunnel 고정 주소와 다릅니다')
    target = re.search(r'^\s*ProxyPass "/" "http://([0-9.]+):8000/"', vhost, re.M)
    if not target or not api_ip or target.group(1) != api_ip.group(1):
        problems.append('apache-vhost.conf: ProxyPass "/" 대상이 docker-compose.yml 의 api tunnel 고정 주소와 다릅니다')
    if not header or not re.search(r'^\s*RequestHeader set ' + re.escape(header.group(1)) + r' "expr=%\{REMOTE_ADDR\}"', vhost, re.M | re.I):
        problems.append('apache-vhost.conf: 고객 IP 머리글을 RequestHeader set 으로 덮어써 넘겨야 합니다(위조 방지)')
    if re.search(r'^\s*ProxyAddHeaders\s+On', vhost, re.M | re.I) or 'ProxyAddHeaders Off' not in vhost:
        problems.append('apache-vhost.conf: ProxyAddHeaders Off 로 X-Forwarded-For 를 넘기지 않습니다(3.12)')

    excluded = re.findall(r'^\s*ProxyPass "([^"]+)" "!"', vhost, re.M)
    general = vhost.find('ProxyPass "/" ')
    for path in ('/assets', '/realtime-ws', '/relay-viewer'):
        if path not in excluded:
            problems.append(f'apache-vhost.conf: {path} 는 Apache 가 맡으므로 ProxyPass "{path}" "!" 로 뺍니다')
        elif vhost.find(f'ProxyPass "{path}" "!"') > general:
            problems.append(f'apache-vhost.conf: {path} 제외 규칙은 ProxyPass "/" 보다 앞에 둡니다(첫 규칙이 이김)')

    # uvicorn 은 기본으로 127.0.0.1 의 프록시 헤더(X-Forwarded-For)를 믿는다.
    api_block = re.search(r'^  api:\n(.*?)(?=^  \S)', compose, re.S | re.M)
    for name, text in (('docker/api/Dockerfile', read('docker/api/Dockerfile')), ('docker-compose.yml api', api_block.group(1) if api_block else '')):
        if 'uvicorn' in text and '--no-proxy-headers' not in text:
            problems.append(f'{name}: uvicorn 에 --no-proxy-headers 가 없습니다')

    if 'X-Forwarded-For' in request_text.replace('X-Forwarded-For 는 쓰지 않는다', ''):
        problems.append('backend/app/core/request.py: X-Forwarded-For 를 읽지 않습니다')

    if problems:
        fail('고객 IP·앞단 프록시 규칙 위반 (CLAUDE.md 3.12)', '\n    '.join(problems))
    else:
        ok('고객 IP 경로 확인 (cloudflared 고정 주소, Apache 덮어쓰기, FastAPI 는 web 주소만, 정적·웹소켓 제외)')


# ------------------------------------------------- 백엔드 설정
def check_backend():
    compose = read('docker-compose.yml')
    db_py = read(f'{APP}/db.py')
    problems = []

    api_block = re.search(r'^  api:\n(.*?)(?=^  \S)', compose, re.S | re.M)
    if 'x-portal-env: &portal-env' not in compose or not api_block or '<<: *portal-env' not in api_block.group(1):
        problems.append('docker-compose.yml: api 가 포털 환경변수 묶음(x-portal-env)을 받지 않습니다')

    # 저장된 데이터와 코드는 엄격 모드가 아닌 동작(길이 초과 자르기 등)을 전제로 한다.
    if db_py.count('STRICT_TRANS_TABLES') < 3 or db_py.count('STRICT_ALL_TABLES') < 3:
        problems.append('backend/app/db.py: sql_mode 에서 STRICT_* 를 빼지 않습니다')

    loose = [line for line in (l.split('#', 1)[0].strip() for l in read('backend/requirements.txt').splitlines()) if line and '==' not in line]
    if loose:
        problems.append('backend/requirements.txt: 버전이 고정되지 않았습니다 — ' + ', '.join(loose))

    for name in ('docker/api/Dockerfile', 'docker/web/Dockerfile'):
        base = re.search(r'^FROM\s+(\S+)', read(name), re.M)
        if not base or ':' not in base.group(1) or base.group(1).endswith((':latest', ':slim')):
            problems.append(f'{name}: 기반 이미지 태그를 고정합니다(예: debian:trixie-20261005-slim)')

    for rel in ('backend/app/core/data/php_html5_entities.json', 'backend/app/core/data/php_pcre_unicode.json'):
        if not exists(rel):
            problems.append(f'{rel} 가 없습니다(xss_clean, 가이드 매칭이 쓰는 데이터)')

    if problems:
        fail('백엔드 설정 규칙 위반', '\n    '.join(problems))
    else:
        ok('백엔드 설정 확인 (환경변수 묶음, sql_mode, 의존성·이미지 버전 고정, 데이터 파일)')


# ------------------------------------------------- 3.13 고객에게 보내는 주소
def check_customer_url():
    """고객 접속 주소와 만족도 조사 주소를 base_url() 로 만들면 상담원이 연 주소(localhost, 내부 IP)가 나간다."""
    # 고객 접속 주소 base_url(org.org_code) 와 만족도 조사 주소 base_url(org.org_code + '/survey...'). 화면 안 이동(/guide 등)은 괜찮다.
    pattern = re.compile(r"""base_url\(\s*org(?:\.org_code|\[['"]org_code['"]\])\s*(?:\)|\+\s*['"]/survey)""")
    found = [f'{rel}:{n}' for rel_dir, suf in ((APP, ('.py',)), (TEMPLATES, ('.html',)))
             for rel, full in walk(rel_dir, suf, ('__pycache__',)) for n, line in lines_of(full) if pattern.search(line)]

    if found:
        fail('고객에게 보내는 주소를 base_url() 로 만들었습니다 (CLAUDE.md 3.13)',
             'customer_url(org.org_code) 를 쓰세요\n    ' + '\n    '.join(found[:10]))
    else:
        ok('고객 접속 주소가 customer_url() 을 거칩니다')


# ------------------------------------- 3.15 가이드
def check_guide():
    """가이드는 고객 화면에 관리자가 쓴 글을 그대로 내보내므로 조용히 깨지면 위험한 것들을 본다."""
    problems = []
    model_rel = f'{APP}/models/guide_model.py'

    # 1) 유튜브는 개인정보 보호 강화 모드 주소로만, iframe 은 guide/_video.html 한 곳에서만 만든다.
    for rel, full in list(walk(TEMPLATES, ('.html',))) + list(walk(JS, ('.js',), ('vendor',))):
        text = open(full, encoding='utf-8').read()
        for number, line in enumerate(text.splitlines(), 1):
            if 'youtube' in line and '/embed/' in line and 'youtube-nocookie.com/embed/' not in line:
                problems.append(f'{rel}:{number} 유튜브 임베드는 youtube-nocookie.com/embed/ 로만 만듭니다')
        if rel.startswith(TEMPLATES) and 'youtube-nocookie.com/embed/' in text and not rel.endswith('guide/_video.html'):
            problems.append(f'{rel} 유튜브 iframe 은 guide/_video.html 을 불러 그립니다')

    # 2) 공개 가이드 템플릿은 |safe 로 이스케이프를 끄지 않는다(글 블록은 guide_text_html 만).
    for rel, full in walk(f'{TEMPLATES}/guide', ('.html',)):
        if '|safe' in open(full, encoding='utf-8').read():
            problems.append(f'{rel} 공개 가이드 템플릿에서 |safe 를 쓰지 않습니다(관리자가 쓴 글을 그대로 내보냄)')

    # 3) 가이드 카드 메시지를 저장하면 발행도 한다(3.11).
    block = py_function(f'{ROUTERS}/console.py', 'api_chat_send_guide')
    if not block or 'publish_new_messages(' not in block:
        problems.append('console.api_chat_send_guide 가 저장한 카드를 발행하지 않습니다(publish_new_messages)')

    # 4) 테마 목록마다 템플릿이 있고, 모든 테마가 탭과 모음 본문을 부른다.
    themes = py_literal(model_rel, function='themes') or {}
    if not themes:
        problems.append('guide_model.themes() 를 찾지 못했습니다')
    for key in themes:
        path = f'{TEMPLATES}/guide/themes/{key}.html'
        if not exists(path):
            problems.append(f'테마 "{key}" 의 템플릿 {path} 가 없습니다')
            continue
        text = read(path)
        for part in ('guide/_tabs.html', 'guide/_collection.html'):
            if part not in text:
                problems.append(f'{path} 가 {part} 를 부르지 않습니다')

    # 5) 가이드 표는 guide_model 만 고친다(검사 clean_article, 색인 rebuild_index 를 함께 처리).
    write = re.compile(r"""db\.(update|insert)\(\s*'guide_(articles|categories|synonyms|terms)'|(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?guide_(articles|categories|synonyms|terms)`?\b""", re.I)
    for rel, full in py_files():
        if rel != model_rel:
            problems += [f'{rel}:{n} 가이드 글·분류·동의어·색인은 guide_model 로만 고칩니다' for n, line in lines_of(full) if write.search(line)]

    # 6) 서버가 외부 주소로 요청하는 곳은 guide_model.fetch_external_image() 하나다(SSRF 검사). 내부 발행(realtime)과 메일은 예외.
    allowed = {model_rel: ('http.client', 'socket', 'ssl'), f'{APP}/libraries/realtime.py': ('httpx',),
               f'{APP}/libraries/mailer.py': ('smtplib', 'ssl')}
    net = re.compile(r'^\s*(?:import|from)\s+(httpx|requests|urllib\.request|http\.client|socket|ssl|smtplib|aiohttp|pycurl)\b', re.M)
    for rel, full in py_files():
        for match in net.finditer(open(full, encoding='utf-8').read()):
            if match.group(1) not in allowed.get(rel, ()):
                problems.append(f'{rel} 에서 {match.group(1)} 를 씁니다. 외부 요청은 guide_model.fetch_external_image() 를 거칩니다')
    block = py_function(model_rel, 'fetch_external_image') or ''
    if not all(part in block for part in ('is_external_image(', 'public_ipv4(', 'store_fetched_image(', 'FETCH_MAX_REDIRECTS')):
        problems.append('fetch_external_image() 는 매번 is_external_image / public_ipv4 로 다시 검사하고 store_fetched_image 로 저장합니다')
    if 'image_file_ext(body)' not in (py_function(model_rel, 'store_fetched_image') or ''):
        problems.append('store_fetched_image() 는 올린 이미지와 같은 image_file_ext() 검사를 거칩니다')

    # 7) 이미지 업로드는 check_uploaded_image() 로 검사한다(내용 기준 형식 판별, SVG 거부).
    for rel, field in ((f'{ROUTERS}/admin.py', 'logo'), (f'{ROUTERS}/admin_guide.py', 'image')):
        if f"check_uploaded_image(post.files.get('{field}')" not in read(rel):
            problems.append(f'{rel} 의 {field} 업로드는 check_uploaded_image() 로 검사합니다')

    # 8) 올린 이미지는 git 에 넣지 않는다.
    if exists('backend/assets/uploads/guides') and 'backend/assets/uploads/guides/*' not in read('.gitignore'):
        problems.append('.gitignore 에 backend/assets/uploads/guides/* 가 없습니다')

    if problems:
        fail('가이드 규칙 위반 (CLAUDE.md 3.15)', '\n    '.join(problems[:12]))
    else:
        ok('가이드 규칙 확인 (nocookie·iframe 한 곳, 이스케이프, 카드 발행, 테마 템플릿·탭, guide_model 만 저장, 외부 요청 한 곳, 업로드 검사)')


# ---------------------------------------------------------------- 3.11, 3.16, 3.18 상담
def check_chat():
    """운영 여부 판단, 발행 범위 안의 저장, 조직 방 이벤트, 방문자·답장 경로."""
    problems = []
    files = py_files()

    # 1) 운영 여부는 chat_hours_model.availability() 안에서만 본다(3.16).
    for rel, full in files:
        if rel in (f'{APP}/models/chat_model.py', f'{APP}/models/chat_hours_model.py'):
            continue
        problems += [f'{rel}:{n} 운영 여부는 chat_hours_model.availability() 로 판단합니다'
                     for n, line in lines_of(full) if re.search(r'\bhas_available_agent\(', line) and 'lambda' not in line]

    # 2) 인사말·자동 응답·가이드 추천은 send() 가 발행할 메시지를 읽기 전에 저장하고 함께 발행한다(3.11, 3.16, 3.18).
    body = py_function(f'{ROUTERS}/widget.py', 'send')
    if body:
        listing = body.find('chat_model.list_messages(conversation.id, first_id - 1)')
        for part in ("'type': 'greeting'", '_auto_reply(', '_suggest_guide('):
            at = body.find(part)
            if at < 0 or listing < 0 or at > listing:
                problems.append(f'widget.send 에서 {part} 가 발행할 메시지를 읽기(list_messages) 전에 있어야 합니다')
        if 'realtime.publish_message(' not in body:
            problems.append('widget.send 가 저장한 메시지를 발행하지 않습니다(realtime.publish_message)')
    else:
        problems.append('routers/widget.py 의 send() 를 찾지 못했습니다')
    init = py_function(f'{ROUTERS}/widget.py', 'init') or ''
    if 'touch_visitor_seen' in init:
        problems.append('widget.init 에서 touch_visitor_seen 을 부르지 않습니다(창을 닫아 둔 방문자도 보는 중으로 셈)')

    # 3) 담당 변경, 보는 중/입력 중 이벤트는 조직 방(publish_org)에만 보낸다(고객 위젯으로 새지 않게, 3.16).
    for rel, full in files:
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"'type':\s*'(presence|assignment)'", text):
            call = text.rfind('publish_', 0, match.start())
            if call < 0 or not text.startswith('publish_org(', call):
                problems.append(f'{rel}:{text.count(chr(10), 0, match.start()) + 1} {match.group(1)} 이벤트는 realtime.publish_org() 로만 보냅니다')

    # 4) visitors 는 visitor_model 만 고친다(연락처 검사 clean_contact, 3.18).
    write = re.compile(r"""db\.(update|insert)\(\s*'visitors'|(UPDATE|INSERT\s+INTO)\s+`?visitors`?\b""", re.I)
    for rel, full in files:
        if rel != f'{APP}/models/visitor_model.py':
            problems += [f'{rel}:{n} visitors 는 visitor_model 로만 고칩니다' for n, line in lines_of(full) if write.search(line)]

    # 5) 상담원 답장을 저장하는 경로는 publish_new_messages() 를 거치고, 그 안에서 팔로업 메일을 판단한다(3.18).
    for rel, full in walk(ROUTERS, ('.py',), ('__pycache__',)):
        text = open(full, encoding='utf-8').read()
        for match in re.finditer(r"add_message\([^)]*?'agent'", text):
            start = text.rfind('\ndef ', 0, match.start())
            end = text.find('\ndef ', match.end())
            if 'publish_new_messages(' not in text[start:end if end > 0 else None]:
                problems.append(f'{rel}:{text.count(chr(10), 0, match.start()) + 1} 상담원 답장을 저장했으면 publish_new_messages() 로 발행합니다')
    block = py_function(f'{ROUTERS}/console.py', 'publish_new_messages') or ''
    if 'chat_followup.after_agent_message(' not in block:
        problems.append('console.publish_new_messages() 가 chat_followup.after_agent_message() 를 불러야 합니다')

    # 6) 실시간은 저장 뒤 발행만 한다. 메시지를 웹소켓으로 받지 않는다(3.11). 컨트롤러가 아니라 모듈이므로 이름 충돌 규칙은 없다.
    if re.search(r'\.onmessage\s*=.*\n(?:.*\n){0,15}?.*apiPost\(', read(f'{JS}/widget.js')):
        warn('widget.js 가 실시간 메시지를 받고 바로 API 로 보내는지 확인하세요(전송은 HTTP API 만)')

    if problems:
        fail('상담 규칙 위반 (CLAUDE.md 3.11, 3.16, 3.18)', '\n    '.join(problems[:12]))
    else:
        ok('상담 규칙 확인 (availability 한 곳, 발행 전 저장, 조직 방 이벤트, visitors 는 모델만, 답장 팔로업)')


# ---------------------------------------------------------------- 3.17 매크로
def check_macro():
    """매크로는 macro_model 만 고친다(검사·이름 중복·추천 색인). 화면은 매크로 글을 HTML 로 넣지 않는다."""
    problems = []

    write = re.compile(r"""db\.(update|insert)\(\s*'(macros|macro_terms)'|(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+`?(macros|macro_terms)`?\b""", re.I)
    for rel, full in py_files():
        if rel != f'{APP}/models/macro_model.py':
            problems += [f'{rel}:{n} macros 는 macro_model 로만 고칩니다' for n, line in lines_of(full) if write.search(line)]

    html = re.compile(r"""innerHTML\s*[+]?=(?!\s*['"]{2}\s*;)|\.html\(\s*[^)\s]""")
    for rel, full in walk(f'{JS}/console', ('.js',)):
        if os.path.basename(rel).startswith('macro'):
            problems += [f'{rel}:{n} 매크로 화면은 textContent 로 그립니다(innerHTML 에 값을 넣지 않음)'
                         for n, line in lines_of(full) if html.search(line)]

    block = py_function(f'{ROUTERS}/console.py', 'api_chat_send') or ''
    if 'record_use(' not in block or 'publish_new_messages(' not in block:
        problems.append('console.api_chat_send 에 매크로 사용 기록(record_use)과 발행(publish_new_messages)이 함께 있어야 합니다')

    if problems:
        fail('매크로 규칙 위반 (CLAUDE.md 3.17)', '\n    '.join(problems[:10]))
    else:
        ok('매크로 규칙 확인 (macro_model 만 저장, 화면 이스케이프, 기존 전송 API 로 사용 기록)')


# ------------------------------------------------------------------ 실행 점검
def check_live():
    base = os.environ.get('PORTAL_BASE', 'http://localhost:8099')
    pages = ['/', '/login', '/signup', '/demo', '/demo/guide', '/healthz']

    for path in pages:
        try:
            with urllib.request.urlopen(base + path, timeout=5) as response:
                body = response.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as exc:
            fail(f'{path} 가 {exc.code} 를 돌려줍니다')
            return
        except (urllib.error.URLError, OSError) as exc:
            warn(f'개발 서버에 접속할 수 없어 실행 점검을 건너뜁니다 ({exc})')
            return

        if 'Internal Server Error' in body or 'Traceback' in body:
            fail(f'{path} 에서 서버 오류가 납니다')
            return

        if path == '/healthz':
            if '"db":true' not in body or '+00:00' not in body:
                fail('/healthz 가 DB 연결이나 UTC 를 확인하지 못했습니다', body[:200])
                return
            continue

        if 'RHGrid.create' in body and 'wgrid.global.js' not in body:
            fail(f'{path} 가 그리드를 쓰는데 use_grid 를 넘기지 않았습니다')
            return

        if 'bi bi-' in body and 'bootstrap-icons.min.css' not in body:
            fail(f'{path} 가 Bootstrap Icons 를 쓰는데 아이콘 글꼴을 싣지 않았습니다')
            return

    ok(f'실행 점검 통과 ({len(pages)}개 주소)')


def main():
    check_bootstrap()
    check_customer_css()
    check_asset_version()
    check_locales()
    check_icons()
    check_utc()
    check_grid()
    check_library_flags()
    check_headers()
    check_api_urls()
    check_permissions()
    check_routes()
    check_widget()
    check_terms()
    check_secrets()
    check_no_vnc()
    check_proxy()
    check_backend()
    check_customer_url()
    check_guide()
    check_chat()
    check_macro()

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
