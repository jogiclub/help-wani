"""
파일 위치: backend/app/db.py
역할: MySQL 연결 (PHP config/database.php 와 같은 문자셋, 시간대, sql_mode)
"""
from sqlalchemy import URL, create_engine, text

from app import config

# PHP 와 같은 DB 를 함께 쓰는 동안 같은 데이터를 다르게 처리하면 안 된다.
# - 시간대: 저장 기준은 UTC (CLAUDE.md 3.4)
# - sql_mode: CI3 는 stricton = FALSE 일 때 STRICT_* 를 뺀다(system/database/drivers/mysqli/mysqli_driver.php).
#   FastAPI 만 strict 이면 PHP 가 넣는 값을 이쪽은 거부하는 차이가 생긴다.
_INIT_COMMAND = (
    "SET SESSION time_zone = '+00:00', sql_mode = "
    "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(@@sql_mode, "
    "'STRICT_ALL_TABLES,', ''), ',STRICT_ALL_TABLES', ''), 'STRICT_ALL_TABLES', ''), "
    "'STRICT_TRANS_TABLES,', ''), ',STRICT_TRANS_TABLES', ''), 'STRICT_TRANS_TABLES', '')"
)

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
    connect_args={'init_command': _INIT_COMMAND},
    pool_pre_ping=True,
    pool_recycle=3600,
)


def ping():
    """DB 에 붙어 시간대와 sql_mode 를 돌려준다. 실패하면 예외를 그대로 올린다."""
    with engine.connect() as conn:
        row = conn.execute(text('SELECT @@session.time_zone AS tz, @@session.sql_mode AS mode')).mappings().one()
        return {'time_zone': row['tz'], 'sql_mode': row['mode']}
