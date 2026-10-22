"""
파일 위치: backend/app/libraries/mailer.py
역할: SMTP 메일 발송 — 상담 위젯 오프라인 알림, 고객 답장 알림

접속 정보는 .env 에서 읽는다. SMTP_HOST 가 비어 있으면 발송을 건너뛴다(개발 환경 기본).
실패해도 예외를 던지지 않고 False 를 돌려준다. 호출한 쪽의 본래 작업(메시지 저장)이 메일 때문에 실패하면 안 된다.
SMTP_CRYPTO 는 CI3 Email 과 같이 tls(STARTTLS) / ssl(처음부터 TLS) / 빈 값(암호화 없음)이다. 시간 제한 10초.
"""
import logging
import smtplib
import ssl
from email.message import EmailMessage
from email.utils import formataddr

from app import config
from app.core.php import php_int, php_str
from app.helpers.validation import valid_email

logger = logging.getLogger('remotehelp')


def is_configured():
    return php_str(config.env('SMTP_HOST', '')) != ''


def send(to, subject, body, options=None):
    """메일 한 통. options: from_name(보낸 사람 이름), reply_to(회신 주소)."""
    options = options or {}
    if not is_configured() or not valid_email(php_str(to)):
        return False

    from_name = options.get('from_name') or php_str(config.env('APP_NAME', 'RemoteHelp'))
    message = EmailMessage()
    message['From'] = formataddr((from_name, php_str(config.env('SMTP_FROM', 'no-reply@localhost'))))
    message['To'] = php_str(to)
    message['Subject'] = subject
    reply_to = php_str(options.get('reply_to'))
    if reply_to and valid_email(reply_to):
        message['Reply-To'] = reply_to
    message.set_content(body, charset='utf-8')

    host = php_str(config.env('SMTP_HOST', ''))
    port = php_int(config.env('SMTP_PORT', 587))
    crypto = php_str(config.env('SMTP_CRYPTO', 'tls')).lower()
    user, password = php_str(config.env('SMTP_USER', '')), php_str(config.env('SMTP_PASS', ''))

    try:
        if crypto == 'ssl':
            client = smtplib.SMTP_SSL(host, port, timeout=10, context=ssl.create_default_context())
        else:
            client = smtplib.SMTP(host, port, timeout=10)
        with client:
            if crypto == 'tls':
                client.starttls(context=ssl.create_default_context())
            if user != '':
                client.login(user, password)
            client.send_message(message)
        return True
    except (OSError, smtplib.SMTPException) as error:
        logger.error('메일 발송 실패: %s', error)
        return False
