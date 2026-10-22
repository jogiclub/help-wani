"""
파일 위치: backend/app/models/organization_model.py
역할: 조직 조회와 설정 (PHP models/Organization_model.php 대응)

지금까지 옮긴 화면에 필요한 함수만 있다. 나머지 함수는 화면을 옮길 때 PHP 와 같은 이름으로 더한다.
"""
from app import db

TABLE = 'organizations'


def get_by_id(org_id):
    return db.first_where(TABLE, {'id': int(org_id)})


def get_by_code(org_code):
    return db.first_where(TABLE, {'org_code': org_code})


def get_active_by_code(org_code):
    return db.first_where(TABLE, {'org_code': org_code, 'status': 'active'})


def insert(data):
    return db.insert(TABLE, data)


def update(org_id, data):
    return db.update(TABLE, data, {'id': int(org_id)})
