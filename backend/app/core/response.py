"""
파일 위치: backend/app/core/response.py
역할: 공통 API 응답 { result, message, data } 생성
"""
from fastapi.responses import Response

from app.core.php import php_json_encode


def api_response(result, message='', data=None, status=200):
    """모든 API 는 이 형식으로 응답한다.

    빈 배열은 [] 로 나가면 런처(C#)가 객체로 읽지 못하므로 None 과 빈 목록은 {} 로 보낸다.
    값이 든 목록은 PHP 와 같이 목록 그대로 보낸다.
    """
    if data is None or (isinstance(data, (list, tuple)) and len(data) == 0):
        data = {}

    # PHP json_encode(..., JSON_UNESCAPED_UNICODE) 와 같은 바이트로 보낸다('/' 는 '\/').
    body = php_json_encode(
        {'result': bool(result), 'message': '' if message is None else str(message), 'data': data},
        unescaped_unicode=True,
    )
    return Response(body, status_code=status, media_type='application/json; charset=utf-8')
