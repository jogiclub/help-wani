#!/usr/bin/env python3
"""
파일 위치: scripts/hooks/post-edit-check.py
역할: 파일을 고친 뒤 프로젝트 규칙 검사를 자동 실행하는 Claude Code 훅

.claude/settings.json 의 PostToolUse(Write|Edit) 훅으로 등록된다.
표준입력으로 받은 도구 정보에서 파일 경로를 꺼내 감시 대상이면 검사를 돌리고,
위반이 있을 때만 결과를 알린다. 통과하면 아무것도 출력하지 않는다.
"""
import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# 이 디렉터리 아래가 바뀐 경우에만 검사한다.
WATCHED = ('portal/', 'relay/', 'launcher/', 'scripts/', 'docker/', 'sql/', 'server/')


def main():
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        return 0

    tool_input = payload.get('tool_input') or {}
    tool_response = payload.get('tool_response') or {}

    path = (tool_response.get('filePath')
            or tool_input.get('file_path')
            or '')

    if not path:
        return 0

    try:
        relative = os.path.relpath(os.path.abspath(path), ROOT)
    except ValueError:
        return 0

    # 저장소 밖이거나 감시 대상이 아니면 조용히 넘어간다.
    if relative.startswith('..') or not relative.startswith(WATCHED):
        return 0

    result = subprocess.run(
        [sys.executable, os.path.join(ROOT, 'scripts', 'check-conventions.py')],
        capture_output=True, text=True, cwd=ROOT, timeout=60)

    if result.returncode == 0:
        return 0

    lines = [line.strip() for line in result.stdout.splitlines() if line.strip().startswith('실패')]
    summary = ' / '.join(line.replace('실패', '').strip() for line in lines) or '프로젝트 규칙 위반'

    print(json.dumps({
        'systemMessage': f'프로젝트 규칙 검사 실패 — {summary}',
        'hookSpecificOutput': {
            'hookEventName': 'PostToolUse',
            'additionalContext': (
                'scripts/check-conventions.py 결과 규칙 위반이 있습니다. '
                'CLAUDE.md 3장을 확인하고 고쳐 주세요.\n\n' + result.stdout.strip()
            ),
        },
    }, ensure_ascii=False))

    return 0


if __name__ == '__main__':
    sys.exit(main())
