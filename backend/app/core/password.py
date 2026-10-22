"""
파일 위치: backend/app/core/password.py
역할: 비밀번호 해시와 검증 (PHP password_hash(PASSWORD_DEFAULT) / password_verify() 와 호환)

- PHP 가 만든 $2y$ bcrypt 해시를 그대로 검증한다. 새로 만드는 해시($2b$)도 PHP password_verify() 가 읽는다.
- PHP bcrypt 는 72바이트 뒤를 버린다. bcrypt 5 는 72바이트를 넘으면 오류를 내므로 PHP 처럼 잘라서 넘긴다.
- 비용은 PHP 8.2 기본값 10.
"""
import bcrypt

PHP_DEFAULT_COST = 10


def _bytes(password):
    return str(password).encode('utf-8')[:72]


def password_hash(password):
    return bcrypt.hashpw(_bytes(password), bcrypt.gensalt(rounds=PHP_DEFAULT_COST)).decode('ascii')


def password_verify(password, hashed):
    if not hashed:
        return False
    try:
        return bcrypt.checkpw(_bytes(password), str(hashed).encode('ascii'))
    except ValueError:
        return False
