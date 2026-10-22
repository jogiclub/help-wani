"""
파일 위치: backend/app/settings.py
역할: 앱 상수 (PHP application/config/constants.php 의 RH_* 대응)
"""
RH_CODE_TTL = 600             # 코드 유효시간(초)
RH_VIEWER_TOKEN_TTL = 60      # 뷰어 토큰 유효시간(초)
RH_VERIFY_FAIL_LIMIT = 5      # IP 당 코드 검증 실패 허용 횟수
RH_VERIFY_BLOCK_WINDOW = 600  # 차단 판정/유지 시간(초)
RH_HEARTBEAT_TIMEOUT = 120    # 하트비트 미수신 종료 판정(초)
RH_LOGO_MAX_BYTES = 1048576   # 조직 로고 최대 크기(1MB)
