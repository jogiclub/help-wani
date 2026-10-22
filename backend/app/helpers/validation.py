"""
파일 위치: backend/app/helpers/validation.py
역할: 입력 검사 규칙 (CI3 Form_validation 규칙 대응)

valid_email 은 CI3 가 쓰는 PHP filter_var(FILTER_VALIDATE_EMAIL) 와 같은 결과를 낸다.
정규식은 php-src php-8.2.34 ext/filter/logical_filters.c 의 regexp1 을 그대로 옮겼다(웹 컨테이너 PHP 8.2.34).
PHP 는 바이트 단위(/iD)로 검사하므로 여기서도 UTF-8 바이트로 검사한다.
CI3 는 intl 확장이 있으면 도메인을 IDN 변환하지만 웹 컨테이너에 intl 이 없어 변환하지 않는다(여기서도 하지 않음).
"""
import re

# /iD : 대소문자 무시, $ 는 문자열 끝에서만(\Z)
_EMAIL_RE = re.compile(rb'''^(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){255,})(?!(?:(?:\x22?\x5C[\x00-\x7E]\x22?)|(?:\x22?[^\x5C\x22]\x22?)){65,}@)(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22))(?:\.(?:(?:[\x21\x23-\x27\x2A\x2B\x2D\x2F-\x39\x3D\x3F\x5E-\x7E]+)|(?:\x22(?:[\x01-\x08\x0B\x0C\x0E-\x1F\x21\x23-\x5B\x5D-\x7F]|(?:\x5C[\x00-\x7F]))*\x22)))*@(?:(?:(?!.*[^.]{64,})(?:(?:(?:xn--)?[a-z0-9]+(?:-+[a-z0-9]+)*\.){1,126}){1,}(?:(?:[a-z][a-z0-9]*)|(?:(?:xn--)[a-z0-9]+))(?:-+[a-z0-9]+)*)|(?:\[(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})|(?:(?!(?:.*[a-f0-9][:\]]){7,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,5})?)))|(?:(?:IPv6:(?:(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){5}:)|(?:(?!(?:.*[a-f0-9]:){5,})(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3})?::(?:[a-f0-9]{1,4}(?::[a-f0-9]{1,4}){0,3}:)?)))?(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))(?:\.(?:(?:25[0-5])|(?:2[0-4][0-9])|(?:1[0-9]{2})|(?:[1-9]?[0-9]))){3}))\]))\Z''', re.IGNORECASE)

# CI3 system/language/english/form_validation_lang.php
MESSAGES = {
    'required': 'The {field} field is required.',
    'valid_email': 'The {field} field must contain a valid email address.',
    'min_length': 'The {field} field must be at least {param} characters in length.',
    'max_length': 'The {field} field cannot exceed {param} characters in length.',
    'alpha_dash': 'The {field} field may only contain alpha-numeric characters, underscores, and dashes.',
    'matches': 'The {field} field does not match the {param} field.',
}


def valid_email(value):
    data = str(value).encode('utf-8')
    # RFC 2821 최대 길이 320 옥텟 (PHP 와 같음)
    if len(data) > 320:
        return False
    return _EMAIL_RE.match(data) is not None


def required(value):
    """CI3 required: 배열이 아니면 trim 한 값이 비어 있으면 실패."""
    if isinstance(value, (list, dict)):
        return bool(value)
    return value is not None and str(value).strip(' \t\n\r\x00\x0b') != ''


def min_length(value, param):
    return str(param).isdigit() and int(param) <= len(str(value if value is not None else ''))


def max_length(value, param):
    return str(param).isdigit() and int(param) >= len(str(value if value is not None else ''))


def alpha_dash(value):
    # PHP '/^[a-z0-9_-]+$/i' 의 $ 는 끝 줄바꿈 앞에서도 맞는다(파이썬 $ 와 같음).
    return re.match(r'^[a-z0-9_-]+$', str(value if value is not None else ''), re.IGNORECASE) is not None


_RULES = {'required': required, 'valid_email': valid_email, 'min_length': min_length,
          'max_length': max_length, 'alpha_dash': alpha_dash}

_PARAM_RE = re.compile(r'^(.*?)\[(.*)\]$')


def run(fields, rules, prefix=' ', suffix=' '):
    """CI3 form_validation->run() + validation_errors(prefix, suffix) 대응(system/libraries/Form_validation.php).

    rules: [(필드, 이름표, ['required', 'min_length[3]', 'matches[password]', ...]), ...]
    통과하면 None, 실패하면 오류 문자열.
    - 'required' 는 맨 앞으로 옮겨 먼저 검사한다(_prepare_rules).
    - 값이 비어 있으면(None, '') required / matches 가 아닌 규칙은 건너뛴다. 배열 값은 빈 값으로 본다.
    - 필드마다 처음 실패한 규칙 하나만 남긴다. {param} 이 다른 필드 이름이면 그 이름표로 바꾼다.
    """
    labels = {field: label for field, label, _ in rules}
    values = {}
    for field, _, _ in rules:
        value = fields.get(field)
        values[field] = None if isinstance(value, (dict, list)) else value

    errors = []
    for field, label, names in rules:
        ordered = (['required'] if 'required' in names else []) + [n for n in names if n != 'required']
        value = values[field]

        for spec in ordered:
            match = _PARAM_RE.match(spec)
            rule, param = (match.group(1), match.group(2)) if match else (spec, None)

            if (value is None or value == '') and rule not in ('required', 'isset', 'matches'):
                continue

            if rule == 'matches':
                ok = param in values and values[param] is not None and value == values[param]
            elif param is not None:
                ok = _RULES[rule](value, param)
            else:
                ok = _RULES[rule](value)

            if not ok:
                shown = labels.get(param, param) if param is not None else ''
                errors.append(MESSAGES[rule].replace('{field}', label).replace('{param}', shown))
                break

    if not errors:
        return None
    return ''.join(prefix + e + suffix + '\n' for e in errors)
