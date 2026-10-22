"""
파일 위치: backend/app/core/errors.py
역할: 처리를 멈추고 바로 응답하기(Halt), 오류 화면(show_error, 404) — CI3 show_error(), show_404(), redirect() 대응

CI3 에서 권한 거부처럼 응답을 보내고 끝낼 때 쓰던 `api_response(); $this->output->_display(); exit;` 는
여기서 `raise Halt(api_response(...))` 로 쓴다. 의존성(권한 검사) 안에서도 멈출 수 있다.
"""
import re

from fastapi.responses import HTMLResponse, RedirectResponse
from markupsafe import escape

from app.core.request import base_url, get_request


class Halt(Exception):
    """이 응답으로 처리를 끝낸다. main.py 의 예외 처리기가 응답을 그대로 돌려준다."""

    def __init__(self, response):
        super().__init__('halt')
        self.response = response


def redirect(path):
    """CI3 redirect() 대응(system/helpers/url_helper.php). HTTP/1.1 이면 GET 은 307, 그 밖의 메서드는 303, 그 외 프로토콜은 302."""
    url = path if re.match(r'^(\w+:)?//', path, re.IGNORECASE) else base_url(path)
    request = get_request()
    if request.scope.get('http_version') == '1.1':
        code = 307 if request.method == 'GET' else 303
    else:
        code = 302
    return RedirectResponse(url, status_code=code)


_ERROR_STYLE = '''::selection { background-color: #E13300; color: white; }
::-moz-selection { background-color: #E13300; color: white; }
body {
	background-color: #fff;
	margin: 40px;
	font: 13px/20px normal Helvetica, Arial, sans-serif;
	color: #4F5155;
}
a {
	color: #003399;
	background-color: transparent;
	font-weight: normal;
}
h1 {
	color: #444;
	background-color: transparent;
	border-bottom: 1px solid #D0D0D0;
	font-size: 19px;
	font-weight: normal;
	margin: 0 0 14px 0;
	padding: 14px 15px 10px 15px;
}
code {
	font-family: Consolas, Monaco, Courier New, Courier, monospace;
	font-size: 12px;
	background-color: #f9f9f9;
	border: 1px solid #D0D0D0;
	color: #002166;
	display: block;
	margin: 14px 0 14px 0;
	padding: 12px 10px 12px 10px;
}
#container {
	margin: 10px;
	border: 1px solid #D0D0D0;
	box-shadow: 0 0 8px #D0D0D0;
}
p {
	margin: 12px 15px 12px 15px;
}'''


def _error_page(title, heading, message, status):
    """CI3 views/errors/html/error_general.php, error_404.php 와 같은 모양."""
    html = (
        '<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n'
        f'<title>{escape(title)}</title>\n<style type="text/css">\n{_ERROR_STYLE}\n</style>\n</head>\n<body>\n'
        f'\t<div id="container">\n\t\t<h1>{escape(heading)}</h1>\n\t\t<p>{escape(message)}</p>\t</div>\n</body>\n</html>'
    )
    return HTMLResponse(html, status_code=status)


def show_error(message, status=500, heading='An Error Was Encountered'):
    """CI3 show_error() 대응 응답. 보통 raise Halt(show_error(...)) 로 쓴다."""
    return _error_page('Error', heading, message, status)


def show_404():
    """CI3 show_404() 대응 응답."""
    return _error_page('404 Page Not Found', '404 Page Not Found', 'The page you requested was not found.', 404)
