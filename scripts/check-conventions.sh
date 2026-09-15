#!/bin/bash
# 파일 위치: scripts/check-conventions.sh
# 역할: CLAUDE.md 의 프로젝트 규칙을 자동 점검한다 (check-conventions.py 래퍼)
exec python3 "$(dirname "$0")/check-conventions.py" "$@"
