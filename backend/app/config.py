"""
파일 위치: backend/app/config.py
역할: 환경변수(.env / docker environment)를 읽는 공통 함수
"""
import os
from pathlib import Path


def env(key, default=None):
    """환경변수를 읽는다. 비어 있으면 기본값, 'true'/'false'/'null' 은 PHP env() 와 같이 바꾼다."""
    value = os.environ.get(key)

    if value is None or value == '':
        return default

    lowered = value.lower()
    if lowered == 'true':
        return True
    if lowered == 'false':
        return False
    if lowered == 'null':
        return None

    return value


APP_NAME = env('APP_NAME', 'RemoteHelp')
APP_ENV = env('APP_ENV', 'production')

DB_HOST = env('DB_HOST', 'db')
DB_PORT = int(env('DB_PORT', 3306))
DB_NAME = env('DB_NAME', 'remotehelp')
DB_USER = env('DB_USER', 'remotehelp')
DB_PASS = env('DB_PASS', 'remotehelp')

# backend/assets(JS, CSS, 사전, 업로드). 앞단 Apache 가 같은 폴더를 내준다. docker-compose 에서 이 경로로 붙인다.
ASSETS_DIR = Path(env('ASSETS_DIR', '/srv/assets'))
