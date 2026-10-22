"""
파일 위치: backend/app/settings/macro.py
역할: 상담 대화 매크로 설정 — 개수·길이 제한, 파일 등록 제한, 추천 가중치와 기준 점수 (PHP config/macro.php 대응)

설계는 docs/macro-plan.md. 전환 기간에는 PHP 설정과 같아야 한다(check-conventions 가 대조한다).
"""

# 개수 제한
MAX_ORG = 2000            # 조직 공용 매크로
MAX_PERSONAL = 300      # 상담원 1명의 개인 매크로
MAX_FAVORITES = 100     # 상담원 1명의 즐겨찾기

# 길이 제한 (글자 수)
NAME_MAX = 40
BODY_MAX = 2000
KEYWORDS_MAX = 300

# 파일 등록 (CSV)
IMPORT_MAX_ROWS = 1000
IMPORT_MAX_BYTES = 5 * 1024 * 1024

# 내용에 넣을 수 있는 변수. 저장은 영문 토큰, 화면에서만 이름으로 보여준다. 값은 콘솔이 불러올 때 채운다.
VARIABLES = {
    'visitor_name': '고객 이름',
    'agent_name': '상담원 이름',
    'org_name': '조직 이름',
}

# 추천 색인 가중치. word = 낱말 그대로, gram = 한글 2글자 조각. 낱말 처리는 guide_matcher 와 같다.
WEIGHTS = {
    'name': {'word': 8, 'gram': 2},
    'keywords': {'word': 10, 'gram': 3},
    'body': {'word': 1, 'gram': 0},
}

# 한 매크로에서 색인하는 낱말 수 상한
MAX_TERMS = 200

# 추천 기준 점수와 개수. 고객 마지막 메시지로 찾는다.
SUGGEST_MIN_SCORE = 6
SUGGEST_LIMIT = 3
