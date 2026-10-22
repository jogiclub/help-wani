"""
파일 위치: backend/app/models/organization_model.py
역할: 조직 조회와 설정 (PHP models/Organization_model.php 대응)

1단계에서는 로그인과 권한 검사에 필요한 조회만 옮겼다. 나머지 함수는 화면을 옮길 때 PHP 와 같은 이름으로 더한다.
"""
from app import db

TABLE = 'organizations'


def get_by_id(org_id):
    return db.first_where(TABLE, {'id': int(org_id)})


def get_by_code(org_code):
    return db.first_where(TABLE, {'org_code': org_code})
