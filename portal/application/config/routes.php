<?php
/**
 * 파일 위치: application/config/routes.php
 * 역할: URL 라우팅 규칙
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'home/index';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// 런처 / 중계 서버용 API
$route['api/launcher/verify']['post']  = 'api/launcher/verify';
$route['api/launcher/status']['post']  = 'api/launcher/status';
$route['api/launcher/heartbeat']['get']= 'api/launcher/heartbeat';
$route['api/launcher/version']['get']  = 'api/launcher/version';
$route['api/relay/auth']['get']        = 'api/relay/auth';
$route['api/realtime/auth']['get']     = 'api/realtime_auth/auth';

// 상담 위젯 (고객사 사이트에서 호출한다. CSRF 제외 대상이라 Origin 과 방문자 토큰으로 막는다)
$route['widget.js']                 = 'widget/script';
$route['api/widget/init']['post']   = 'widget/init';
$route['api/widget/send']['post']   = 'widget/send';
$route['api/widget/poll']['get']    = 'widget/poll';
$route['api/widget/contact']['post'] = 'widget/contact';

// 서비스 소개
$route['home'] = 'home/index';
$route['lang/(:any)'] = 'home/set_locale/$1';

// 인증
$route['login']  = 'auth/login';
$route['logout'] = 'auth/logout';
$route['signup'] = 'auth/signup';

// 상담원 콘솔
$route['console']                          = 'console/index';
$route['console/session/(:num)']           = 'console/session/$1';
$route['console/history']                  = 'console/history';
$route['console/api/session/create']       = 'console/api_session_create';
$route['console/api/session/list']         = 'console/api_session_list';
$route['console/api/session/detail/(:num)']= 'console/api_session_detail/$1';
$route['console/api/session/viewer-token'] = 'console/api_viewer_token';
$route['console/api/session/end']          = 'console/api_session_end';
$route['console/api/session/note']         = 'console/api_session_note';
$route['console/api/session/log']          = 'console/api_session_log';

// 상담 위젯 대화 (상담원 쪽)
$route['console/chat']                     = 'console/chat';
$route['console/api/chat/list']            = 'console/api_chat_list';
$route['console/api/chat/messages/(:num)'] = 'console/api_chat_messages/$1';
$route['console/api/chat/send']            = 'console/api_chat_send';
$route['console/api/chat/close']           = 'console/api_chat_close';
$route['console/api/chat/my_status']       = 'console/api_chat_my_status';
$route['console/api/chat/assign']          = 'console/api_chat_assign';
$route['console/api/chat/presence']        = 'console/api_chat_presence';
$route['console/api/chat/notify']          = 'console/api_chat_notify';
$route['console/api/chat/remote']          = 'console/api_chat_remote';
$route['console/api/chat/send_guide']      = 'console/api_chat_send_guide';
$route['console/api/guide/suggest/(:num)'] = 'console/api_guide_suggest/$1';
$route['console/api/guide/search']         = 'console/api_guide_search';

// 상담 대화 매크로 (docs/macro-plan.md)
$route['console/macro']                    = 'console_macro/index';
$route['console/macro/template']           = 'console_macro/template';
$route['console/macro/export']             = 'console_macro/export';
$route['console/api/macro/suggest/(:num)'] = 'console_macro/api_suggest/$1';
$route['console/api/macro/(:any)']         = 'console_macro/api_$1';

// 플랫폼 운영자 (조직 가입 승인)
$route['operator']              = 'operator/index';
$route['operator/audit']        = 'operator/audit';
$route['operator/api/(:any)']   = 'operator/api_$1';

// 조직 관리
$route['admin']                 = 'admin/index';
$route['admin/agents']          = 'admin/agents';
$route['admin/organization']    = 'admin/organization';
$route['admin/widget']          = 'admin/widget';
$route['admin/api/(:any)']      = 'admin/api_$1';
// 조직 관리 > 가이드 관리 (docs/guide-plan.md)
$route['admin/guide']               = 'admin_guide/index';
$route['admin/guide/new']           = 'admin_guide/edit';
$route['admin/guide/edit/(:num)']   = 'admin_guide/edit/$1';
$route['admin/guide/preview/(:num)']= 'admin_guide/preview/$1';
$route['admin/guide/export']        = 'admin_guide/export';
$route['admin/guide/api/(:any)']    = 'admin_guide/api_$1';

// 고객 화면 (가장 마지막에 둔다)
$route['(:any)/download'] = 'download/launcher/$1';
$route['(:any)/survey/(:num)'] = 'customer/survey/$1/$2';
// 팔로업 메일의 '대화 이어 가기' 주소(docs/widget-ops-plan.md 3-5)
$route['(:any)/chat/(:any)'] = 'customer/chat/$1/$2';
// 모음 페이지는 글 주소 규칙보다 위. 낱말은 Guide_model::RESERVED_SLUGS 에도 넣는다(docs/guide-collections-plan.md).
$route['(:any)/guide/features'] = 'guide/features/$1';
$route['(:any)/guide/videos']   = 'guide/videos/$1';
$route['(:any)/guide']        = 'guide/index/$1';
$route['(:any)/guide/(:any)'] = 'guide/article/$1/$2';
$route['(:any)'] = 'customer/index/$1';
