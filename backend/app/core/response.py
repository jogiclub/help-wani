"""
파일 위치: backend/app/core/response.py
역할: 공통 API 응답 { result, message, data } 생성 (PHP api_response() 대응)
"""
from fastapi.responses import JSONResponse


def api_response(result, message='', data=None, status=200):
    """모든 API 는 이 형식으로 응답한다.

    빈 배열은 [] 로 나가면 런처(C#)가 객체로 읽지 못하므로 None 과 빈 목록은 {} 로 보낸다.
    값이 든 목록은 PHP 와 같이 목록 그대로 보낸다.
    """
    if data is None or (isinstance(data, (list, tuple)) and len(data) == 0):
        data = {}

    return JSONResponse(
        {'result': bool(result), 'message': '' if message is None else str(message), 'data': data},
        status_code=status,
    )
