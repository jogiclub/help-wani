"""
파일 위치: backend/app/routers/console_macro.py
역할: 상담 대화 매크로 관리 화면과 API — 목록, 만들기/고치기, 삭제, 개인으로 복사, 즐겨찾기, 추천, CSV 양식/내려받기/올리기
      (PHP controllers/Console_macro.php 대응)

모든 상담원이 들어온다. 공용 매크로를 바꾸는 요청은 macro_model 이 조직 관리자인지 다시 확인한다.
매크로로 채운 글의 전송은 console.api_chat_send 가 한다(저장·발행 경로를 하나로, CLAUDE.md 3.17).
"""
from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response
from starlette.concurrency import run_in_threadpool

from app import db
from app.core.deps import current_agent
from app.core.input import PostData, php_list, post_data, query_data
from app.core.php import php_date, php_empty, php_int, php_str
from app.core.request import client_ip
from app.core.response import api_response
from app.core.templates import render
from app.models import chat_model, log_model, macro_model
from app.settings import macro as macro_conf

router = APIRouter(prefix='/console')

_METHODS = ['GET', 'POST']


def _ids(value):
    """PHP (array) $this->input->post('ids'): 배열이면 값 목록, 값 하나면 그 하나, 없으면 빈 목록."""
    if value is None:
        return []
    if isinstance(value, (dict, list)):
        return php_list(value)
    return [value]


# ------------------------------------------------------------------ 화면

@router.api_route('/macro', methods=_METHODS)
def index(request: Request, agent=Depends(current_agent)):
    return render(request, 'console/macro', {
        'page_title': '매크로',
        'use_grid': True,
        'use_dropzone': True,
        'is_admin': agent.role == 'admin',
        'rows': macro_model.list_for_agent(agent),
        'macro_editor': macro_model.editor_conf(agent),
        'import_rows': macro_conf.IMPORT_MAX_ROWS,
        'import_mb': int(macro_conf.IMPORT_MAX_BYTES / 1024 / 1024),
    })


def _send_csv(name, content):
    return Response(content.encode('utf-8'), media_type='text/csv; charset=utf-8',
                    headers={'Content-Disposition': f'attachment; filename="{name}"', 'Cache-Control': 'no-store'})


@router.api_route('/macro/template', methods=_METHODS)
def template(agent=Depends(current_agent)):
    """파일 등록 양식(CSV, 예시 한 줄)."""
    return _send_csv('macro-template.csv', macro_model.to_csv())


@router.api_route('/macro/export', methods=_METHODS)
def export(request: Request, agent=Depends(current_agent), query: PostData = Depends(query_data)):
    """?scope=org|personal"""
    scope = macro_model.scope_value(php_str(query.xss('scope'))) or 'personal'
    rows = macro_model.export_rows(agent, scope)

    if scope == 'org':
        log_model.audit(agent.org_id, agent.id, 'macro_export', 'rows=' + str(len(rows)), client_ip(request))

    return _send_csv('macro-' + scope + '-' + php_date('Ymd') + '.csv', macro_model.to_csv(rows))


# ------------------------------------------------------------------ API

@router.api_route('/api/macro/list', methods=_METHODS)
def api_list(agent=Depends(current_agent)):
    """공용 + 내 개인 매크로 전체. 콘솔 / 목록과 관리 목록이 함께 쓴다."""
    return api_response(True, '', {'macros': macro_model.list_for_agent(agent)})


@router.api_route('/api/macro/save', methods=_METHODS)
def api_save(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """id, scope = org | personal, name, body, keywords. id 가 있으면 고치기이며 범위는 바꾸지 않는다."""
    macro_id = php_int(post.get('id'))
    scope = macro_model.scope_value(php_str(post.xss('scope')))

    if macro_id == 0 and scope is None:
        return api_response(False, '범위 값이 올바르지 않습니다.', None, 400)

    # 내용은 상담원이 쓴 글 그대로 저장하고 화면에서 이스케이프한다(XSS 필터가 글을 바꾸지 않게).
    saved = macro_model.save(agent, scope, {
        'name': post.get('name'),
        'body': post.get('body'),
        'keywords': post.get('keywords'),
    }, macro_id)

    if isinstance(saved, str):
        return api_response(False, saved, None, 400)

    found = macro_model.get_usable(agent, saved)
    if found.owner_agent_id is None:
        log_model.audit(agent.org_id, agent.id, 'macro_update' if macro_id > 0 else 'macro_create',
                        'macro_id=' + str(saved) + ' name=' + found.name, client_ip(request))

    unknown = macro_model.unknown_variables(found.body)
    message = ('저장했습니다. 알 수 없는 변수 {' + '}, {'.join(unknown) + '} 는 바뀌지 않고 그대로 보내집니다.') if unknown else '저장했습니다.'
    return api_response(True, message, {'id': saved})


@router.api_route('/api/macro/delete', methods=_METHODS)
def api_delete(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """ids[]"""
    ids = _ids(post.get('ids'))
    if php_empty(ids):
        return api_response(False, '지울 매크로를 골라 주세요.', None, 400)

    deleted = macro_model.delete(agent, ids)

    for item in deleted:
        if item['scope'] == 'org':
            log_model.audit(agent.org_id, agent.id, 'macro_delete',
                            'macro_id=' + str(item['id']) + ' name=' + item['name'], client_ip(request))

    if not deleted:
        return api_response(False, '지울 수 있는 매크로가 없습니다. 공용 매크로는 조직 관리자만 지울 수 있습니다.', None, 403)

    skipped = len({php_int(i) for i in ids}) - len(deleted)
    message = f'{len(deleted)}개를 지웠습니다.' + (f' 권한이 없는 {skipped}개는 남겼습니다.' if skipped > 0 else '')
    return api_response(True, message, {'ids': [item['id'] for item in deleted]})


@router.api_route('/api/macro/copy', methods=_METHODS)
def api_copy(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """쓸 수 있는 매크로를 내 개인 매크로로 복사한다."""
    saved = macro_model.copy_to_personal(agent, php_int(post.get('id')))
    if isinstance(saved, str):
        return api_response(False, saved, None, 400)
    return api_response(True, '개인 매크로로 복사했습니다.', {'id': saved})


@router.api_route('/api/macro/favorite', methods=_METHODS)
def api_favorite(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """id, on = 0 | 1"""
    on = not php_empty(post.get('on'))
    result = macro_model.set_favorite(agent, php_int(post.get('id')), on)
    if isinstance(result, str):
        return api_response(False, result, None, 400)
    return api_response(True, '즐겨찾기에 넣었습니다.' if on else '즐겨찾기에서 뺐습니다.')


@router.api_route('/api/macro/favorite_order', methods=_METHODS)
def api_favorite_order(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """ids[] 위에서부터 차례"""
    macro_model.set_favorite_order(agent, _ids(post.get('ids')))
    return api_response(True, '순서를 저장했습니다.')


@router.api_route('/api/macro/suggest/{conversation_id:int}', methods=_METHODS)
def api_suggest(conversation_id: int, agent=Depends(current_agent)):
    """그 대화의 마지막 고객 메시지로 매크로를 찾는다(AI 없음)."""
    conversation = chat_model.get_conversation(conversation_id)
    if conversation is None or int(conversation.org_id) != int(agent.org_id):
        return api_response(False, '대화를 찾을 수 없습니다.', None, 404)

    last = db.row("SELECT body FROM messages WHERE conversation_id = :id AND sender_type = 'visitor' ORDER BY id DESC LIMIT 1",
                  {'id': int(conversation.id)})
    return api_response(True, '', {'items': macro_model.suggest(agent, last.body) if last else []})


@router.api_route('/api/macro/import', methods=_METHODS)
async def api_import(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """multipart(필드명 file), scope = org | personal, mode = skip | overwrite.

    오류가 한 행이라도 있으면 아무것도 저장하지 않고 오류 목록을 돌려준다.
    """
    scope = macro_model.scope_value(php_str(post.xss('scope')))
    mode = 'overwrite' if post.get('mode') == 'overwrite' else 'skip'
    upload = post.files.get('file')

    if scope is None:
        return api_response(False, '범위 값이 올바르지 않습니다.', None, 400)

    if not macro_model.can_edit_scope(agent, scope):
        return api_response(False, '공용 매크로는 조직 관리자만 올릴 수 있습니다.', None, 403)

    if upload is None or isinstance(upload, dict) or not upload.filename:
        return api_response(False, 'CSV 파일을 선택해 주세요.', None, 400)

    content = await upload.read()
    if len(content) > macro_conf.IMPORT_MAX_BYTES:
        return api_response(False, f'파일은 {int(macro_conf.IMPORT_MAX_BYTES / 1024 / 1024)}MB 이하로 올려 주세요.', None, 400)

    parsed = await run_in_threadpool(macro_model.parse_csv, content)
    if 'error' in parsed:
        return api_response(False, parsed['error'], None, 400)

    if not parsed['rows']:
        return api_response(False, '등록할 행이 없습니다.', None, 400)

    result = await run_in_threadpool(macro_model.import_rows, agent, scope, parsed['rows'], mode)

    if 'errors' in result:
        count = sum(1 for e in result['errors'] if e['row'] > 0)
        message = f'{count}개 행에 오류가 있어 아무것도 저장하지 않았습니다.' if count > 0 else result['errors'][0]['message']
        return api_response(False, message, {'errors': result['errors']}, 400)

    if scope == 'org':
        await run_in_threadpool(
            log_model.audit, agent.org_id, agent.id, 'macro_import',
            'file=' + php_str(upload.filename)[:80] + ' mode=' + mode + ' created=' + str(result['created'])
            + ' updated=' + str(result['updated']) + ' skipped=' + str(result['skipped']), client_ip(request))

    return api_response(True, f"추가 {result['created']}개, 덮어씀 {result['updated']}개, 건너뜀 {result['skipped']}개", result)
