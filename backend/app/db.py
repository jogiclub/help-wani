"""
파일 위치: backend/app/db.py
역할: MySQL 연결과 쿼리 도우미 (PHP config/database.php, CI3 mysqli 드라이버와 같은 동작)

PHP 와 같은 DB 를 함께 쓰는 동안 같은 데이터를 다르게 다루면 안 되므로 다음을 PHP 와 맞춘다.
- 시간대: 저장 기준은 UTC (CLAUDE.md 3.4)
- sql_mode: CI3 는 stricton = FALSE 일 때 STRICT_* 를 뺀다(system/database/drivers/mysqli/mysqli_driver.php).
- 결과 값: mysqli 처럼 모든 값을 문자열(NULL 은 None)로 돌려준다. 그래야 옮긴 코드의 비교·JSON 응답이 PHP 와 같다
  (예: "id":"12", 날짜 "2026-10-22 01:33:41"). 숫자가 필요하면 PHP 처럼 int() 로 바꿔 쓴다.
- 영향 행 수: SQLAlchemy 는 FOUND_ROWS 를 켜 '조건에 맞은 행' 을 세지만, mysqli 는 '실제로 바뀐 행' 을 센다.
  UPDATE 한 번의 결과로 판단하는 코드(팔로업 메일 등)가 같게 동작하도록 FOUND_ROWS 를 끈다.
"""
from contextlib import contextmanager
from contextvars import ContextVar

import pymysql
from pymysql.constants import CLIENT
from sqlalchemy import URL, bindparam, create_engine, text

from app import config

_INIT_COMMAND = (
    "SET SESSION time_zone = '+00:00', sql_mode = "
    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@@sql_mode, "
    "'STRICT_ALL_TABLES,', ''), ',STRICT_ALL_TABLES', ''), 'STRICT_ALL_TABLES', ''), "
    "'STRICT_TRANS_TABLES,', ''), ',STRICT_TRANS_TABLES', ''), 'STRICT_TRANS_TABLES', '')"
)

# 값을 넣을 때의 변환(encoders)만 남기고 읽을 때의 변환(decoders, 키가 정수)은 뺀다 → 결과가 모두 문자열.
_ENCODERS_ONLY = {k: v for k, v in pymysql.converters.conversions.items() if not isinstance(k, int)}

engine = create_engine(
    URL.create(
        'mysql+pymysql',
        username=config.DB_USER,
        password=config.DB_PASS,
        host=config.DB_HOST,
        port=config.DB_PORT,
        database=config.DB_NAME,
        query={'charset': 'utf8mb4'},
    ),
    connect_args={
        'init_command': _INIT_COMMAND,
        'conv': _ENCODERS_ONLY,
        # connect_args 는 방언이 만든 인자를 덮어쓴다. PyMySQL 이 기본 능력 플래그를 더하므로 0 이면 FOUND_ROWS 만 빠진다.
        'client_flag': 0,
    },
    pool_pre_ping=True,
    pool_recycle=3600,
)

assert not (CLIENT.CAPABILITIES & CLIENT.FOUND_ROWS)

# transaction() 안에서는 같은 연결을 쓴다.
_current_conn = ContextVar('rh_db_conn', default=None)


class Row(dict):
    """CI3 의 row() 결과(stdClass)처럼 row.name 으로도, row['name'] 으로도 읽는다. JSON 으로는 dict 그대로 나간다."""

    def __getattr__(self, name):
        try:
            return self[name]
        except KeyError:
            raise AttributeError(name) from None


def _expand(sql, params):
    """목록 값은 IN (...) 에 쓰도록 펼친다."""
    stmt = text(sql)
    for key, value in (params or {}).items():
        if isinstance(value, (list, tuple, set)):
            stmt = stmt.bindparams(bindparam(key, expanding=True))
    return stmt


@contextmanager
def _connection():
    conn = _current_conn.get()
    if conn is not None:
        yield conn
        return

    # transaction() 밖에서는 문장마다 바로 반영한다(mysqli 자동 커밋과 같음).
    with engine.begin() as conn:
        yield conn


def query(sql, params=None):
    """여러 행을 Row 목록으로 돌려준다."""
    with _connection() as conn:
        result = conn.execute(_expand(sql, params), params or {})
        return [Row(r) for r in result.mappings().all()]


def row(sql, params=None):
    """첫 행 하나. 없으면 None."""
    rows = query(sql, params)
    return rows[0] if rows else None


def scalar(sql, params=None):
    """첫 행 첫 칸. 없으면 None."""
    with _connection() as conn:
        return conn.execute(_expand(sql, params), params or {}).scalar()


class ExecResult:
    def __init__(self, rowcount, lastrowid):
        self.rowcount = rowcount
        self.lastrowid = lastrowid


def execute(sql, params=None):
    """INSERT / UPDATE / DELETE. 영향 행 수(rowcount)와 새 id(lastrowid)를 돌려준다."""
    with _connection() as conn:
        result = conn.execute(_expand(sql, params), params or {})
        return ExecResult(result.rowcount, result.lastrowid)


def like_pattern(value):
    """CI3 like() 대응 값: ! % _ 를 ! 로 이스케이프하고 앞뒤에 % 를 붙인다. SQL 에는 LIKE :x ESCAPE '!' 로 쓴다."""
    escaped = str(value).replace('!', '!!').replace('%', '!%').replace('_', '!_')
    return '%' + escaped + '%'


def _ident(name):
    if not name.replace('_', '').isalnum():
        raise ValueError(f'허용하지 않는 이름: {name}')
    return f'`{name}`'


def _where(where):
    parts, params = [], {}
    for i, (column, value) in enumerate(where.items()):
        key = f'w{i}'
        if value is None:
            parts.append(f'{_ident(column)} IS NULL')
        else:
            parts.append(f'{_ident(column)} = :{key}')
            params[key] = value
    return ' AND '.join(parts) or '1 = 1', params


def get_where(table, where, limit=None):
    """CI3 get_where() 대응. 조건은 모두 = (None 은 IS NULL)."""
    clause, params = _where(where)
    sql = f'SELECT * FROM {_ident(table)} WHERE {clause}'
    if limit is not None:
        sql += f' LIMIT {int(limit)}'
    return query(sql, params)


def first_where(table, where):
    """CI3 get_where()->row() 대응. 없으면 None."""
    rows = get_where(table, where, limit=1)
    return rows[0] if rows else None


def insert(table, data):
    """CI3 insert() + insert_id() 대응. 새 id 를 int 로 돌려준다."""
    columns = ', '.join(_ident(c) for c in data)
    values = ', '.join(f':v{i}' for i in range(len(data)))
    params = {f'v{i}': v for i, v in enumerate(data.values())}
    return int(execute(f'INSERT INTO {_ident(table)} ({columns}) VALUES ({values})', params).lastrowid or 0)


def update(table, data, where):
    """CI3 where()->update() + affected_rows() 대응. 실제로 바뀐 행 수를 돌려준다."""
    sets = ', '.join(f'{_ident(c)} = :s{i}' for i, c in enumerate(data))
    params = {f's{i}': v for i, v in enumerate(data.values())}
    clause, where_params = _where(where)
    params.update(where_params)
    return execute(f'UPDATE {_ident(table)} SET {sets} WHERE {clause}', params).rowcount


@contextmanager
def transaction():
    """CI3 trans_start() ~ trans_complete() 대응. 안에서 부르는 쿼리는 모두 같은 연결, 예외가 나면 되돌린다."""
    if _current_conn.get() is not None:
        # 안쪽 트랜잭션은 바깥에 합친다(CI3 도 중첩 트랜잭션을 하나로 본다).
        yield
        return

    with engine.begin() as conn:
        token = _current_conn.set(conn)
        try:
            yield
        finally:
            _current_conn.reset(token)


def ping():
    """DB 에 붙어 시간대와 sql_mode 를 돌려준다. 실패하면 예외를 그대로 올린다."""
    found = row('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode')
    return {'time_zone': found.tz, 'sql_mode': found.mode}
