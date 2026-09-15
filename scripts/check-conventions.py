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


# ------------------------------------------------------------------ 3.5 AG Grid
def check_grid():
    enterprise = ('agSetColumnFilter', 'agRichSelectCellEditor', 'agGroupCellRenderer',
                  'sideBar', 'enableRangeSelection')
    found = []

    for rel, full in walk('portal/assets/js', ('.js',)):
        body = open(full, encoding='utf-8').read()
        for name in enterprise:
            if name in body:
                found.append(f'{rel}: {name}')

    if found:
        fail('AG Grid Enterprise 기능을 쓰고 있습니다', '\n    '.join(found))
        return

    grid_js = read('portal/assets/js/grid.js')
    size = re.search(r'DEFAULT_PAGE_SIZE\s*=\s*(\d+)', grid_js)

    if not size or size.group(1) != '100':
        fail('그리드 기본 페이지 크기가 100 이 아닙니다', 'portal/assets/js/grid.js')
    else:
        ok('AG Grid 설정 확인 (Community, 기본 100개)')


# ------------------------------------------------------------- 3.7 파일 상단 주석
def check_headers():
    targets = (
        ('portal/application/controllers', ('.php',), ()),
        ('portal/application/models', ('.php',), ()),
        ('portal/application/helpers', ('.php',), ()),
        ('portal/application/core', ('.php',), ()),
        ('portal/assets/js', ('.js',), ('vendor',)),
        ('relay/src', ('.js',), ()),
        ('launcher/RemoteHelp.App', ('.cs',), ('bin', 'obj')),
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


# ------------------------------------------------------------------ 3.8 라우팅
def check_routes():
    lines = read('portal/application/config/routes.php').splitlines()
    any_rule_at = None

    for index, line in enumerate(lines):
        if not line.strip().startswith('$route['):
            continue

        if "(:any)']" in line and 'survey' not in line and 'download' not in line \
                and 'lang/' not in line and 'admin/api' not in line and 'operator/api' not in line:
            any_rule_at = index
        elif any_rule_at is not None:
            fail('조직 코드 규칙 $route[\'(:any)\'] 아래에 다른 라우트가 있습니다',
                 f'{index + 1}행: {line.strip()} — (:any) 규칙은 맨 아래여야 합니다')
            return

    ok('라우팅 순서 확인')


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


# ------------------------------------------------------------------ 실행 점검
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
        if 'RHGrid.create' in body and 'ag-grid-community' not in body:
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
    check_routes()
    check_secrets()
    check_no_vnc()

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
