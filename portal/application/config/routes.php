<?php
/**
 * 파일 위치: application/config/routes.php
 * 역할: URL 라우팅 규칙
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$route['default_controller'] = 'auth/login';
$route['404_override'] = '';
$route['translate_uri_dashes'] = FALSE;

// 런처 / 중계 서버용 API
$route['api/launcher/verify']['post']  = 'api/launcher/verify';
$route['api/launcher/status']['post']  = 'api/launcher/status';
$route['api/launcher/heartbeat']['get']= 'api/launcher/heartbeat';
$route['api/launcher/version']['get']  = 'api/launcher/version';
$route['api/relay/auth']['get']        = 'api/relay/auth';

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

// 조직 관리
$route['admin']                 = 'admin/index';
$route['admin/agents']          = 'admin/agents';
$route['admin/organization']    = 'admin/organization';
$route['admin/api/(:any)']      = 'admin/api_$1';

// 고객 화면 (가장 마지막에 둔다)
$route['(:any)/survey/(:num)'] = 'customer/survey/$1/$2';
$route['(:any)'] = 'customer/index/$1';
